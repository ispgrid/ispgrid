<?php

use App\Actions\Import\ImportCustomers;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Models\User;
use App\Notifications\NotificationRegistry;
use App\Notifications\Notifier;
use App\Notifications\SendResult;
use App\Support\Selfcare;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusAttributeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Notifications\FakeProvider;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RadiusAttributeSeeder::class]);
    AppSetting::put('phone_digits', '10');
    turnSelfcareOn();
    FakeProvider::reset();
    app(NotificationRegistry::class)->registerProvider(FakeProvider::class);
    NotificationProvider::query()->create(['provider' => 'fake', 'enabled' => true, 'settings' => ['token' => 'tok12345']]);
    $this->actingAs(User::factory()->create());
});

function selfcareRule(string $provider = 'fake', array $values = ['username', 'selfcare_password']): NotificationRule
{
    return NotificationRule::factory()->enabled()->create([
        'trigger' => 'selfcare_login', 'trigger_settings' => [], 'provider' => $provider, 'values' => $values,
        'approved_text' => 'User {1} password {2}',
    ]);
}

it('sends the same new password by every enabled rule', function () {
    NotificationProvider::query()->create(['provider' => 'msg91', 'enabled' => true, 'settings' => ['auth_key' => 'k1']]);
    NotificationProvider::query()->create(['provider' => 'wacrm', 'enabled' => true, 'settings' => ['base_url' => 'https://crm.example.test/', 'api_key' => 'wacrm_live_k']]);
    selfcareRule('msg91', ['username', 'selfcare_password']);
    selfcareRule('wacrm', ['selfcare_password', 'selfcare_url']);
    NotificationRule::query()->update(['template' => ['template_id' => 't1', 'variables' => '', 'name' => 'selfcare', 'language' => 'en']]);
    Http::fake([
        'control.msg91.com/*' => Http::response(['type' => 'success', 'message' => 'req-1']),
        'crm.example.test/*' => Http::response(['data' => ['message_id' => 'm-1', 'whatsapp_message_id' => null]], 201),
    ]);
    $account = Account::factory()->create(['username' => 'alice', 'mobile' => '9876543210', 'whatsapp' => '9876543210']);

    expect(Selfcare::sendLogin($account))->toBeTrue();

    $sms = collect(Http::recorded())->first(fn (array $pair): bool => str_contains($pair[0]->url(), 'msg91'))[0];
    $whatsapp = collect(Http::recorded())->first(fn (array $pair): bool => str_contains($pair[0]->url(), 'crm.example.test'))[0];
    $smsPassword = $sms->data()['recipients'][0]['VAR2'];
    $whatsappPassword = data_get($whatsapp->data(), 'template.params.0');

    expect($smsPassword)->toBe($whatsappPassword)
        ->and($smsPassword)->not->toBe(NotificationLog::SECRET_MASK)
        ->and(Hash::check($smsPassword, $account->fresh()->selfcare_password))->toBeTrue()
        ->and(NotificationLog::query()->where('status', NotificationStatus::Sent)->count())->toBe(2);
});

it('keeps the password out of the log after sending', function () {
    selfcareRule();
    $account = Account::factory()->create(['mobile' => '9876543210']);

    Selfcare::sendLogin($account);

    $sentPassword = FakeProvider::$sent[0]->values[1];
    $log = NotificationLog::query()->sole();
    $raw = DB::table('notification_log')->where('id', $log->id)->first();

    expect($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->values[1])->toBe(NotificationLog::SECRET_MASK)
        ->and($log->preview)->toContain(NotificationLog::SECRET_MASK)->not->toContain($sentPassword)
        ->and($raw->secret)->toBeNull()
        ->and((bool) $raw->has_secret)->toBeTrue()
        ->and(json_encode($raw))->not->toContain($sentPassword);
});

it('keeps the secret encrypted while the message waits in the queue', function () {
    Queue::fake();
    selfcareRule();
    $account = Account::factory()->create(['mobile' => '9876543210']);

    Selfcare::sendLogin($account);

    $raw = DB::table('notification_log')->sole();
    $plain = NotificationLog::query()->sole()->secret;

    expect($plain)->toMatch('/^[A-HJ-NP-Za-km-z2-9]{10}$/')
        ->and($raw->secret)->not->toContain($plain)
        ->and(Hash::check($plain, $account->fresh()->selfcare_password))->toBeTrue()
        ->and(NotificationLog::query()->sole()->toArray())->not->toHaveKey('secret');
});

it('wipes the secret after a final failure and offers no retry', function () {
    FakeProvider::$next = SendResult::failed('Number rejected', retryable: false);
    selfcareRule();
    $account = Account::factory()->create(['mobile' => '9876543210']);

    Selfcare::sendLogin($account);

    $log = NotificationLog::query()->sole();
    expect($log->status)->toBe(NotificationStatus::Failed)
        ->and(DB::table('notification_log')->value('secret'))->toBeNull();

    Livewire::test('pages::notifications.log')->assertDontSee(__('Retry'))
        ->call('retry', $log->id)->assertNotFound();

    expect($log->fresh()->status)->toBe(NotificationStatus::Failed);
});

it('takes the password out of a provider error', function () {
    $log = NotificationLog::query()->create([
        'notification_rule_id' => selfcareRule()->id, 'account_id' => Account::factory()->create()->id, 'provider' => 'fake',
        'channel' => 'sms', 'to' => '+919876543210', 'template' => [], 'values' => ['alice', NotificationLog::SECRET_MASK],
        'status' => NotificationStatus::Queued, 'once_key' => 'k1', 'secret' => 'Abc23456xy', 'has_secret' => true,
    ]);
    FakeProvider::$next = SendResult::failed('Bad value Abc23456xy', retryable: false);

    SendNotification::dispatchSync($log->id);

    expect(FakeProvider::$sent[0]->values)->toBe(['alice', 'Abc23456xy'])
        ->and($log->fresh()->error)->not->toContain('Abc23456xy')
        ->and(DB::table('notification_log')->value('secret'))->toBeNull();
});

it('sends a fresh message on every Send, never blocked as a duplicate', function () {
    Queue::fake();
    selfcareRule();
    $account = Account::factory()->create(['mobile' => '9876543210']);

    Selfcare::sendLogin($account);
    Selfcare::sendLogin($account->fresh());

    expect(NotificationLog::query()->count())->toBe(2);
    Queue::assertPushed(SendNotification::class, 2);
});

it('says when no Selfcare login rule is on', function () {
    $account = Account::factory()->create(['mobile' => '9876543210']);

    expect(Selfcare::sendLogin($account))->toBeFalse()
        ->and($account->fresh()->selfcare_password)->toBeNull();

    selfcareRule();
    AppSetting::put('selfcare.enabled', '0');

    expect(Selfcare::sendLogin($account))->toBeFalse()
        ->and($account->fresh()->selfcare_password)->toBeNull();
});

it('sends the login from the customer card', function () {
    Queue::fake();
    $account = Account::factory()->create(['mobile' => '9876543210']);

    Livewire::test('pages::customers.selfcare-card', ['accountId' => $account->id])
        ->call('send')->assertDispatched('toast', message: __('No Selfcare login message is switched on. Set a password and copy it instead.'));

    selfcareRule();

    Livewire::test('pages::customers.selfcare-card', ['accountId' => $account->id])
        ->call('send')->assertDispatched('toast', message: __('A new selfcare password is on its way to the customer.'));

    expect(NotificationLog::query()->count())->toBe(1)
        ->and($account->fresh()->selfcare_must_change)->toBeTrue();
});

it('sends the login when staff add a customer and a rule is on', function () {
    Queue::fake();
    selfcareRule();

    Livewire::test('pages::customers.form')
        ->set('fullname', 'Bina Rao')->set('service_type', 'PPP')->set('username', 'bina')->set('password', 'secret12')
        ->set('mobile', '9876543210')
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::query()->where('username', 'bina')->sole();
    expect($account->selfcare_must_change)->toBeTrue()
        ->and($account->selfcare_password)->not->toBeNull()
        ->and(NotificationLog::query()->sole()->has_secret)->toBeTrue();
    Queue::assertPushed(SendNotification::class, 1);
});

it('sends nothing on create when selfcare is off or no rule is on', function (bool $selfcareOn, bool $ruleOn) {
    Queue::fake();
    AppSetting::put('selfcare.enabled', $selfcareOn ? '1' : '0');

    if ($ruleOn) {
        selfcareRule();
    }

    Livewire::test('pages::customers.form')
        ->set('fullname', 'Bina Rao')->set('service_type', 'PPP')->set('username', 'bina')->set('password', 'secret12')
        ->set('mobile', '9876543210')
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::query()->where('username', 'bina')->sole()->selfcare_password)->toBeNull()
        ->and(NotificationLog::query()->count())->toBe(0);
})->with([
    'selfcare off' => [false, true],
    'no rule' => [true, false],
]);

it('never sends from the CSV import', function () {
    Queue::fake();
    selfcareRule();
    $columns = array_fill_keys(app(ImportCustomers::class)->columns(), '');

    app(ImportCustomers::class)->import([['line' => 2, 'values' => [...$columns, 'username' => 'imported', 'password' => 'secret12', 'service_type' => 'PPP', 'fullname' => 'Imported One', 'mobile' => '9123456789']]]);

    expect(NotificationLog::query()->count())->toBe(0)
        ->and(Account::query()->where('username', 'imported')->sole()->selfcare_password)->toBeNull();
});

it('test send uses a sample password, not a real one', function () {
    $rule = selfcareRule();
    Account::factory()->create(['mobile' => '9876543210', 'expiry' => now()->addDays(3)]);

    app(Notifier::class)->sendTest($rule, '+919876543210');

    expect(FakeProvider::$sent[0]->values[1])->toBe(NotificationLog::SAMPLE_SECRET)
        ->and(Account::query()->whereNotNull('selfcare_password')->count())->toBe(0)
        ->and(DB::table('notification_log')->value('secret'))->toBeNull();
});

it('tells the operator the DLT template needs the password variable', function () {
    Livewire::test('pages::notifications.rule-form')
        ->set('trigger', 'selfcare_login')
        ->assertSee(__('MSG91 needs a DLT-approved template that includes the password variable.'));
});
