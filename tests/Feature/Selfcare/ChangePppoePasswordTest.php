<?php

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\ServiceType;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Radacct;
use App\Support\Selfcare;
use Database\Seeders\RadiusAttributeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RadiusAttributeSeeder::class);
    turnSelfcareOn(pppoePasswordChange: true);
    Queue::fake();
    $this->account = Account::factory()->selfcare('Selfcare-pass-1')->create(['username' => 'kushal', 'password' => 'old-pppoe-1']);
    app(SyncAccountToRadius::class)->handle($this->account);
});

function radiusPassword(string $username): ?string
{
    return DB::table('radcheck')->where('username', $username)->where('attribute', 'Cleartext-Password')->value('value');
}

function changePppoe(string $selfcarePassword, string $new, ?string $confirm = null): Testable
{
    return Livewire::test('pages::selfcare.pppoe-password')
        ->set('selfcare_password', $selfcarePassword)->set('password', $new)->set('password_confirmation', $confirm ?? $new)
        ->call('save');
}

it('changes the PPPoE password in radcheck and disconnects', function () {
    Radacct::factory()->create(['username' => 'kushal']);
    actingAsCustomer($this->account);

    $this->get(route('selfcare.pppoe-password'))->assertOk()
        ->assertSee(__('You will be disconnected now. Put the new password in your router, or you will stay offline.'));

    changePppoe('Selfcare-pass-1', 'new-pppoe-9')->assertHasNoErrors()->assertRedirect(route('selfcare.dashboard'));

    expect(radiusPassword('kushal'))->toBe('new-pppoe-9')
        ->and($this->account->fresh()->password)->toBe('new-pppoe-9');
    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->username === 'kushal');
});

it('does not disconnect a customer who is offline', function () {
    actingAsCustomer($this->account);

    changePppoe('Selfcare-pass-1', 'new-pppoe-9')->assertHasNoErrors();

    Queue::assertNotPushed(DisconnectAccount::class);
});

it('needs the selfcare password', function () {
    actingAsCustomer($this->account);

    changePppoe('wrong-one', 'new-pppoe-9')->assertHasErrors('selfcare_password');

    expect(radiusPassword('kushal'))->toBe('old-pppoe-1');
});

it('refuses quotes, spaces and backslashes like the staff form', function (string $bad) {
    actingAsCustomer($this->account);

    changePppoe('Selfcare-pass-1', $bad)->assertHasErrors('password');

    expect(radiusPassword('kushal'))->toBe('old-pppoe-1');
})->with(['with "quote"', 'with space', 'back\\slash', "it's", 'पासवर्ड123']);

it('needs the new password twice', function () {
    actingAsCustomer($this->account);

    changePppoe('Selfcare-pass-1', 'new-pppoe-9', 'new-pppoe-8')->assertHasErrors('password');
});

it('is not found for IPoE or while the switch is off', function (Closure $change) {
    actingAsCustomer($this->account);
    $page = Livewire::test('pages::selfcare.pppoe-password')
        ->set('selfcare_password', 'Selfcare-pass-1')->set('password', 'new-pppoe-9')->set('password_confirmation', 'new-pppoe-9');

    $change($this->account);

    $page->call('save')->assertNotFound();
    $this->get(route('selfcare.pppoe-password'))->assertNotFound();
    if (Selfcare::isOpen()) {
        $this->get(route('selfcare.dashboard'))->assertOk()->assertDontSee(route('selfcare.pppoe-password'));
    }

    expect($this->account->fresh()->password)->toBe('old-pppoe-1')
        ->and(DB::table('radcheck')->where('attribute', 'Cleartext-Password')->where('value', 'new-pppoe-9')->exists())->toBeFalse();
    Queue::assertNotPushed(DisconnectAccount::class);
})->with([
    'IPoE' => fn (Account $account) => $account->update(['service_type' => ServiceType::Ipoe]),
    'switch off' => fn (Account $account) => AppSetting::put('selfcare.pppoe_password_change', '0'),
    'selfcare off' => fn (Account $account) => AppSetting::put('selfcare.enabled', '0'),
]);

it('logs the change without the password', function () {
    actingAsCustomer($this->account);

    changePppoe('Selfcare-pass-1', 'new-pppoe-9')->assertHasNoErrors();

    $logged = Activity::query()->where('event', 'pppoe-password-changed')->sole();
    expect($logged->causer_id)->toBe($this->account->id)
        ->and($logged->subject_id)->toBe($this->account->id)
        ->and(Activity::query()->get()->toJson())->not->toContain('new-pppoe-9');
});

it('links the page from the dashboard for PPPoE customers', function () {
    actingAsCustomer($this->account);

    $this->get(route('selfcare.dashboard'))->assertSee(route('selfcare.pppoe-password'));
});
