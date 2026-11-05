<?php

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Http\Middleware\SelfcareSession;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\Selfcare;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    turnSelfcareOn();
});

function selfcareLogin(string $username, string $password): Testable
{
    return Livewire::test('pages::selfcare.login')->set('username', $username)->set('password', $password)->call('login');
}

it('has its address at /selfcare', function () {
    expect(Selfcare::url())->toBe(route('selfcare.login'))
        ->and(route('selfcare.login', absolute: false))->toBe('/selfcare');
});

it('shows the login page', function () {
    $this->get(route('selfcare.login'))->assertOk()->assertSee(__('Log in to Selfcare'));
});

it('logs a customer in with username and selfcare password, not the PPPoE password', function () {
    $account = Account::factory()->selfcare('Selfcare-pass-1')->create(['password' => 'pppoe-secret']);

    selfcareLogin($account->username, 'pppoe-secret')->assertHasErrors('username');
    expect(Auth::guard('selfcare')->check())->toBeFalse();

    selfcareLogin($account->username, 'Selfcare-pass-1')->assertHasNoErrors()->assertRedirect(route('selfcare.dashboard'));

    expect(Auth::guard('selfcare')->id())->toBe($account->id)
        ->and(Auth::guard('web')->check())->toBeFalse()
        ->and($account->fresh()->selfcare_last_login_at)->not->toBeNull();
});

it('refuses a wrong password with one message that does not say whether the username exists', function () {
    $account = Account::factory()->selfcare()->create();

    $wrong = selfcareLogin($account->username, 'not-the-password')->errors()->get('username');
    $unknown = selfcareLogin('nobody-here', 'not-the-password')->errors()->get('username');

    expect($wrong)->toBe($unknown)->and($wrong)->not->toBeEmpty();
});

it('refuses customers who are not eligible', function (Closure $make) {
    $account = $make();

    selfcareLogin($account->username, 'Selfcare-pass-1')->assertHasErrors('username');

    expect(Auth::guard('selfcare')->check())->toBeFalse();
})->with([
    'terminated' => fn () => Account::factory()->selfcare()->terminated()->create(),
    'hotspot' => fn () => Account::factory()->selfcare()->hotspot()->create(),
    'no selfcare password' => fn () => Account::factory()->create(),
]);

it('throttles after 5 tries a minute', function () {
    $account = Account::factory()->selfcare()->create();

    foreach (range(1, 5) as $try) {
        selfcareLogin($account->username, 'wrong-'.$try)->assertHasErrors('username');
    }

    $blocked = selfcareLogin($account->username, 'Selfcare-pass-1');

    expect($blocked->errors()->first('username'))->toContain(__('Too many tries.'))
        ->and(Auth::guard('selfcare')->check())->toBeFalse();

    $this->travel(61)->seconds();

    selfcareLogin($account->username, 'Selfcare-pass-1')->assertHasNoErrors();
});

it('forces a new password after a staff-set one', function () {
    $account = Account::factory()->selfcare('Staff-set-1', mustChange: true)->create();
    selfcareLogin($account->username, 'Staff-set-1')->assertHasNoErrors();

    $this->get(route('selfcare.dashboard'))->assertRedirect(route('selfcare.password'));
    $this->get(route('selfcare.password'))->assertOk()->assertDontSee(__('Current password'));

    $this->travel(5)->seconds();

    Livewire::test('pages::selfcare.password')
        ->set('password', 'Staff-set-1')->set('password_confirmation', 'Staff-set-1')
        ->call('save')->assertHasErrors('password');

    Livewire::test('pages::selfcare.password')
        ->set('password', 'My-own-pass-9')->set('password_confirmation', 'My-own-pass-9')
        ->call('save')->assertHasNoErrors()->assertRedirect(route('selfcare.dashboard'));

    $account->refresh();
    expect($account->selfcare_must_change)->toBeFalse()
        ->and(Hash::check('My-own-pass-9', $account->selfcare_password))->toBeTrue()
        ->and($account->selfcare_password_set_at->isAfter(now()->subSeconds(2)))->toBeTrue();

    $this->get(route('selfcare.dashboard'))->assertOk();
});

it('needs the current password for a change the customer starts', function () {
    $account = Account::factory()->selfcare('Selfcare-pass-1')->create();
    actingAsCustomer($account);

    Livewire::test('pages::selfcare.password')
        ->set('current_password', 'wrong')->set('password', 'My-own-pass-9')->set('password_confirmation', 'My-own-pass-9')
        ->call('save')->assertHasErrors('current_password');

    Livewire::test('pages::selfcare.password')
        ->set('current_password', 'Selfcare-pass-1')->set('password', 'My-own-pass-9')->set('password_confirmation', 'My-own-pass-9')
        ->call('save')->assertHasNoErrors();

    expect(Hash::check('My-own-pass-9', $account->fresh()->selfcare_password))->toBeTrue();
});

it('logs the customer out when staff set a new password', function () {
    $account = Account::factory()->selfcare()->create();
    actingAsCustomer($account);
    $this->get(route('selfcare.dashboard'))->assertOk();

    $account->forceFill(['selfcare_password' => Hash::make('New-staff-1'), 'selfcare_must_change' => true, 'selfcare_password_set_at' => now()])->save();

    $this->get(route('selfcare.dashboard'))->assertRedirect(route('selfcare.login'));
    expect(Auth::guard('selfcare')->check())->toBeFalse();
});

it('keeps this session but ends other sessions after the customer changes their own password', function () {
    $account = Account::factory()->selfcare('Selfcare-pass-1')->create();
    actingAsCustomer($account);
    $otherDevice = session(SelfcareSession::FINGERPRINT);

    Livewire::test('pages::selfcare.password')
        ->set('current_password', 'Selfcare-pass-1')->set('password', 'My-own-pass-9')->set('password_confirmation', 'My-own-pass-9')
        ->call('save')->assertHasNoErrors();

    $this->get(route('selfcare.dashboard'))->assertOk();

    session([SelfcareSession::FINGERPRINT => $otherDevice]);

    $this->get(route('selfcare.dashboard'))->assertRedirect(route('selfcare.login'));
});

it('logs the customer out after 30 idle minutes', function () {
    actingAsCustomer(Account::factory()->selfcare()->create());

    $this->travel(29)->minutes();
    $this->get(route('selfcare.dashboard'))->assertOk();

    $this->travel(31)->minutes();
    $this->get(route('selfcare.dashboard'))->assertRedirect(route('selfcare.login'));
    $this->get(route('selfcare.login'))->assertSee(__('You were logged out after 30 minutes without activity.'));
});

it('logs the customer out when terminated or deleted', function (Closure $change) {
    $account = Account::factory()->selfcare()->create();
    actingAsCustomer($account);
    $this->get(route('selfcare.dashboard'))->assertOk();

    $change($account);

    $this->get(route('selfcare.dashboard'))->assertRedirect(route('selfcare.login'));
    expect(Auth::guard('selfcare')->check())->toBeFalse();
})->with([
    'terminated' => fn (Account $account) => $account->update(['status_id' => AccountStatus::Terminated]),
    'made hotspot' => fn (Account $account) => $account->update(['service_type' => ServiceType::Hotspot]),
    'deleted' => fn (Account $account) => $account->delete(),
]);

it('keeps staff and selfcare logins apart', function () {
    $staff = User::factory()->create();
    $account = Account::factory()->selfcare()->create();

    actingAsCustomer($account);
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    Auth::guard('selfcare')->logout();
    $this->actingAs($staff);
    $this->get(route('selfcare.dashboard'))->assertRedirect(route('selfcare.login'));

    actingAsCustomer($account);
    $this->post(route('selfcare.logout'))->assertRedirect(route('selfcare.login'));

    expect(Auth::guard('selfcare')->check())->toBeFalse()
        ->and(Auth::guard('web')->id())->toBe($staff->id);
    $this->get(route('dashboard'))->assertOk();
});

it('writes successful and failed logins to the activity log without the password', function () {
    $account = Account::factory()->selfcare('Selfcare-pass-1')->create();

    selfcareLogin($account->username, 'Wrong-pass-77');
    selfcareLogin($account->username, 'Selfcare-pass-1');

    $failed = Activity::query()->where('event', 'selfcare-login-failed')->sole();
    $success = Activity::query()->where('event', 'selfcare-login')->sole();

    expect($failed->subject_id)->toBe($account->id)
        ->and($failed->causer_id)->toBe($account->id)
        ->and($success->subject_id)->toBe($account->id)
        ->and($success->causer_type)->toBe(Account::class)
        ->and(Activity::query()->pluck('properties')->toJson())->not->toContain('Selfcare-pass-1')->not->toContain('Wrong-pass-77');
});

it('is not found while selfcare is off', function () {
    AppSetting::put('selfcare.enabled', '0');
    actingAsCustomer(Account::factory()->selfcare()->create());

    $this->get(route('selfcare.login'))->assertNotFound()->assertSee(__('Selfcare is not available.'));
    $this->get(route('selfcare.dashboard'))->assertNotFound();
    $this->get(route('selfcare.password'))->assertNotFound();
    $this->post(route('selfcare.logout'))->assertNotFound();
});
