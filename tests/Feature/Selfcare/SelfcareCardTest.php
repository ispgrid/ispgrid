<?php

use App\Actions\Customers\SetSelfcarePassword;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\Selfcare;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    turnSelfcareOn();
    AppSetting::put('date_format', 'd/m/Y');
    $this->actingAs(User::factory()->create());
});

function selfcareCard(Account $account): Testable
{
    return Livewire::test('pages::customers.selfcare-card', ['accountId' => $account->id]);
}

it('sets a random password, shows it once and stores only a hash', function () {
    $account = Account::factory()->create();

    $card = selfcareCard($account)->call('setPassword')->assertHasNoErrors();
    $shown = $card->get('shownPassword');
    $account->refresh();

    expect($shown)->toMatch('/^[A-HJ-NP-Za-km-z2-9]{10}$/')
        ->and($account->selfcare_password)->not->toBe($shown)
        ->and(Hash::check($shown, $account->selfcare_password))->toBeTrue()
        ->and($account->selfcare_must_change)->toBeTrue()
        ->and($account->selfcare_password_set_at)->not->toBeNull();
    $card->assertSee($shown)->assertSee(__('This password is shown only now.'));

    $card->call('closeDialog')->assertSet('shownPassword', null)->assertDontSee($shown);

    $logged = Activity::query()->where('event', 'selfcare-password-set')->sole();
    expect($logged->subject_id)->toBe($account->id)
        ->and(json_encode($logged->properties))->not->toContain($shown);
});

it('accepts a typed password that meets the rules', function () {
    $account = Account::factory()->create();

    selfcareCard($account)->call('setPassword', 'Typed-pass-1')->assertHasNoErrors()->assertSet('shownPassword', 'Typed-pass-1');

    expect(Hash::check('Typed-pass-1', $account->fresh()->selfcare_password))->toBeTrue();
});

it('refuses a typed password that is too short', function () {
    $account = Account::factory()->create();

    selfcareCard($account)->call('setPassword', 'short')->assertHasErrors('typed')->assertSet('shownPassword', null);

    expect($account->fresh()->selfcare_password)->toBeNull();
});

it('copies the address, username and password as one message', function () {
    $account = Account::factory()->create(['username' => 'alice']);

    expect(Selfcare::details($account, 'Abc23456xy'))->toBe(implode("\n", [
        'Selfcare: '.url('/selfcare'),
        'Username: alice',
        'Password: Abc23456xy',
        'Choose your own password when you first log in.',
    ]));

    selfcareCard($account)->call('setPassword', 'Typed-pass-1')->assertSee('Password: Typed-pass-1');
});

it('shows whether a password is set and the last login', function () {
    $none = Account::factory()->create();
    selfcareCard($none)->assertSee(__('No selfcare password yet.'));

    $account = Account::factory()->create();
    app(SetSelfcarePassword::class)->handle($account, 'Typed-pass-1');
    $account->forceFill(['selfcare_password_set_at' => Carbon::parse('2026-11-01 10:15:00'), 'selfcare_last_login_at' => Carbon::parse('2026-11-02 18:40:00')])->save();

    selfcareCard($account)->assertSee('01/11/2026 10:15')->assertSee('02/11/2026 18:40');
});

it('needs the Set selfcare passwords box', function () {
    $account = Account::factory()->create();
    $this->actingAs(staffLacking(Permission::SetSelfcarePasswords));

    selfcareCard($account)->call('setPassword')->assertForbidden();
    expect($account->fresh()->selfcare_password)->toBeNull();

    $this->get(route('customers.show', $account))->assertOk()->assertDontSeeLivewire('pages::customers.selfcare-card');

    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::SetSelfcarePasswords));
    $this->get(route('customers.show', $account))->assertSeeLivewire('pages::customers.selfcare-card');
});

it('is hidden while selfcare is off and for hotspot customers', function () {
    $hotspot = Account::factory()->hotspot()->create();
    $this->get(route('customers.show', $hotspot))->assertOk()->assertDontSeeLivewire('pages::customers.selfcare-card');

    $account = Account::factory()->create();
    $this->get(route('customers.show', $account))->assertSeeLivewire('pages::customers.selfcare-card');

    AppSetting::put('selfcare.enabled', '0');
    $this->get(route('customers.show', $account))->assertDontSeeLivewire('pages::customers.selfcare-card');
    selfcareCard($account)->call('setPassword')->assertNotFound();
});
