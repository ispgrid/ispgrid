<?php

use App\Actions\Customers\ResetPppoePassword;
use App\Enums\DisconnectReason;
use App\Enums\Permission;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Models\Radacct;
use App\Notifications\NotificationRegistry;
use App\Support\PasswordPattern;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusAttributeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Notifications\FakeProvider;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RadiusAttributeSeeder::class]);
    FakeProvider::reset();
    app(NotificationRegistry::class)->registerProvider(FakeProvider::class);
    NotificationProvider::query()->create(['provider' => 'fake', 'enabled' => true, 'settings' => ['token' => 'tok12345']]);
    $this->account = Account::factory()->create(['username' => 'ravi', 'fullname' => 'Ravi Kumar', 'mobile' => '9876543210', 'password' => 'old-pass-1']);
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::EditCustomers));
});

function pppoeCard(Account $account): Testable
{
    return Livewire::test('pages::customers.pppoe-card', ['accountId' => $account->id]);
}

function pppoeRule(): NotificationRule
{
    return NotificationRule::factory()->enabled()->create([
        'trigger' => 'pppoe_password', 'trigger_settings' => [], 'provider' => 'fake', 'values' => ['username', 'pppoe_password'],
        'approved_text' => 'User {1} password {2}',
    ]);
}

function radiusPasswordOf(string $username): ?string
{
    return DB::table('radcheck')->where('username', $username)->where('attribute', 'Cleartext-Password')->value('value');
}

it('resets the PPPoE password, writes FreeRADIUS and shows it once', function () {
    $card = pppoeCard($this->account)->call('resetPassword');

    $password = $this->account->fresh()->password;
    expect($password)->not->toBe('old-pass-1')->toMatch('/^[A-Za-z2-9]{10}$/')
        ->and(radiusPasswordOf('ravi'))->toBe($password);
    $card->assertSee($password)->assertSee('Username: ravi');

    $activity = Activity::query()->where('event', 'pppoe-password-reset')->sole();
    expect(json_encode($activity->toArray()))->not->toContain($password);

    pppoeCard($this->account)->assertDontSee($password);
});

it('disconnects an online customer after a reset', function () {
    Queue::fake();
    Radacct::factory()->create(['username' => 'ravi']);

    app(ResetPppoePassword::class)->handle($this->account);

    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->username === 'ravi' && $job->reason === DisconnectReason::PppoePasswordReset);
});

it('uses the password pattern when one is set', function () {
    AppSetting::put(PasswordPattern::SETTING, '{first_name}{mobile_last4}');

    expect(app(ResetPppoePassword::class)->handle($this->account))->toBe('ravi3210')
        ->and(radiusPasswordOf('ravi'))->toBe('ravi3210');
});

it('sends the new password by the PPPoE password rule', function () {
    pppoeRule();

    pppoeCard($this->account)->assertSee('Reset and send by SMS/WhatsApp')->call('resetAndSend')->assertHasNoErrors();

    $password = $this->account->fresh()->password;
    $log = NotificationLog::query()->sole();
    expect(FakeProvider::$sent)->toHaveCount(1)
        ->and(FakeProvider::$sent[0]->values)->toBe(['ravi', $password])
        ->and($log->values)->toBe(['ravi', NotificationLog::SECRET_MASK])
        ->and($log->getRawOriginal('secret'))->toBeNull();
});

it('offers no send without a rule', function () {
    pppoeCard($this->account)->assertDontSee('Reset and send by SMS/WhatsApp');
});

it('refuses a reset without Edit customers', function () {
    $this->actingAs(staffHolding(Permission::ViewCustomers));

    pppoeCard($this->account)->call('resetPassword')->assertForbidden();
    expect($this->account->fresh()->password)->toBe('old-pass-1');
});

it('shows the card only for PPPoE customers staff may edit', function () {
    $ipoe = Account::factory()->ipoe()->create();
    $deleted = Account::factory()->create();
    $deleted->delete();

    Livewire::test('pages::customers.show', ['account' => $this->account->id])->assertSee('Reset PPPoE password');
    Livewire::test('pages::customers.show', ['account' => $ipoe->id])->assertDontSee('Reset PPPoE password');
    Livewire::test('pages::customers.show', ['account' => $deleted->id])->assertDontSee('Reset PPPoE password');

    $this->actingAs(staffHolding(Permission::ViewCustomers));
    Livewire::test('pages::customers.show', ['account' => $this->account->id])->assertDontSee('Reset PPPoE password');
});

it('refuses a reset of an IPoE or deleted customer', function () {
    $ipoe = Account::factory()->ipoe()->create();

    pppoeCard($ipoe)->call('resetPassword')->assertNotFound();
});

it('requires the PPPoE password in the values of a PPPoE password rule', function () {
    $this->actingAs(staffHolding(Permission::ManageNotificationRules));

    Livewire::test('pages::notifications.rule-form')
        ->set('name', 'PPPoE password')
        ->set('trigger', 'pppoe_password')
        ->set('provider', 'fake')
        ->set('values', ['username'])
        ->call('save')
        ->assertHasErrors(['values']);
});

it('shows the password when the message cannot go out', function () {
    pppoeRule();
    $this->account->update(['mobile' => null]);

    $card = pppoeCard($this->account)->call('resetAndSend');

    $password = $this->account->fresh()->password;
    expect($password)->not->toBe('old-pass-1');
    $card->assertSee($password)->assertDispatched('toast', message: 'The message could not go out (no valid mobile number). Give the customer this password.');
});

it('makes a new password on every reset, even with a pattern without digits', function () {
    pppoeRule();
    AppSetting::put(PasswordPattern::SETTING, '{first_name}{mobile_last4}');

    pppoeCard($this->account)->call('resetAndSend');
    $first = $this->account->fresh()->password;
    pppoeCard($this->account)->call('resetAndSend');
    $second = $this->account->fresh()->password;

    expect($first)->toBe('ravi3210')
        ->and($second)->not->toBe('ravi3210')
        ->and(FakeProvider::$sent)->toHaveCount(2)
        ->and(FakeProvider::$sent[1]->values)->toBe(['ravi', $second]);
});
