<?php

use App\Enums\AccountStatus;
use App\Http\Middleware\SelfcareSession;
use App\Models\Account;
use App\Models\AppSetting;
use App\Support\AccountNumbers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    turnSelfcareOn();
    AppSetting::put('phone_digits', '10');
});

function mobileLogin(string $typed, string $password): Testable
{
    return Livewire::test('pages::selfcare.login')->set('username', $typed)->set('password', $password)->call('login');
}

it('labels the field for every way to log in', function () {
    $this->get(route('selfcare.login'))->assertSee('Username, account ID or mobile number');
});

it('logs in by account ID with or without the prefix', function () {
    AppSetting::put(AccountNumbers::PREFIX, 'LINK-');
    AppSetting::put(AccountNumbers::START, '1');
    $account = Account::factory()->selfcare('Selfcare-pass-1')->create(['account_number' => 'LINK-1000001']);

    mobileLogin('LINK-1000001', 'Selfcare-pass-1')->assertHasNoErrors()->assertRedirect(route('selfcare.dashboard'));
    expect(Auth::guard('selfcare')->id())->toBe($account->id);

    Auth::guard('selfcare')->logout();
    mobileLogin('1000001', 'Selfcare-pass-1')->assertHasNoErrors()->assertRedirect(route('selfcare.dashboard'));
    expect(Auth::guard('selfcare')->id())->toBe($account->id);
});

it('tries the username before the account ID and the mobile', function () {
    $byName = Account::factory()->selfcare('Name-pass-1')->create(['username' => '9876543210', 'mobile' => '9111111111']);
    Account::factory()->selfcare('Mobile-pass-1')->create(['mobile' => '9876543210']);

    mobileLogin('9876543210', 'Mobile-pass-1')->assertHasErrors('username');
    mobileLogin('9876543210', 'Name-pass-1')->assertHasNoErrors();

    expect(Auth::guard('selfcare')->id())->toBe($byName->id)
        ->and(session(SelfcareSession::MOBILE))->toBeNull();
});

it('logs in by mobile when the password matches any connection on it', function () {
    $home = Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210']);
    $shop = Account::factory()->create(['mobile' => '9876543210']);
    Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9000000000']);

    mobileLogin('9876543210', 'Home-pass-1')->assertHasNoErrors()->assertRedirect(route('selfcare.connections'));

    expect(Auth::guard('selfcare')->id())->toBe($home->id)
        ->and(session(SelfcareSession::LOGIN_ID))->toBe($home->id)
        ->and(session(SelfcareSession::MOBILE))->toBe('9876543210')
        ->and(Activity::query()->where('event', 'selfcare-login')->sole()->subject_id)->toBe($home->id)
        ->and($shop->id)->not->toBe($home->id);
});

it('opens the dashboard straight away for the only connection on a mobile', function () {
    Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210']);

    mobileLogin('9876543210', 'Home-pass-1')->assertHasNoErrors()->assertRedirect(route('selfcare.dashboard'));
});

it('finds a mobile typed with a country code, spaces or a leading zero', function (string $typed) {
    $account = Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210']);

    mobileLogin($typed, 'Home-pass-1')->assertHasNoErrors();

    expect(Auth::guard('selfcare')->id())->toBe($account->id);
})->with(['+91 98765 43210', '919876543210', '09876543210', '98765-43210']);

it('refuses a wrong password by mobile with the usual message', function () {
    Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210']);

    $wrong = mobileLogin('9876543210', 'not-it')->errors()->get('username');
    $unknown = mobileLogin('nobody-here', 'not-it')->errors()->get('username');

    expect($wrong)->toBe($unknown)->and(Auth::guard('selfcare')->check())->toBeFalse();
});

it('stops after 20 failed tries an hour on one mobile in any format', function () {
    Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210']);

    foreach (range(1, 20) as $try) {
        RateLimiter::clear('selfcare|'.($try % 2 === 0 ? '9876543210' : '+919876543210').'|127.0.0.1');
        mobileLogin($try % 2 === 0 ? '9876543210' : '+919876543210', 'wrong-'.$try);
    }

    mobileLogin('09876543210', 'Home-pass-1')->assertHasErrors('username');
    expect(Auth::guard('selfcare')->check())->toBeFalse();
});

it('never opens a Terminated connection by mobile', function () {
    Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210', 'status_id' => AccountStatus::Terminated]);

    mobileLogin('9876543210', 'Home-pass-1')->assertHasErrors('username');
});
