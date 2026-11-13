<?php

use App\Enums\AccountStatus;
use App\Http\Middleware\SelfcareSession;
use App\Models\Account;
use App\Models\AppSetting;
use App\Support\AccountNumbers;
use App\Support\Selfcare;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
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

it('counts failed tries against the customer however they are named', function () {
    AppSetting::put(AccountNumbers::PREFIX, 'LINK-');
    AppSetting::put(AccountNumbers::START, '1');
    Account::factory()->selfcare('Home-pass-1')->create(['username' => 'ravi', 'account_number' => 'LINK-1000001', 'mobile' => '9876543210']);

    foreach (range(1, 20) as $try) {
        $typed = ['ravi', 'LINK-1000001', '1000001', '9876543210'][$try % 4];
        RateLimiter::clear('selfcare|'.strtolower($typed).'|127.0.0.1');
        mobileLogin($typed, 'wrong-'.$try);
    }

    RateLimiter::clear('selfcare|ravi|127.0.0.1');
    mobileLogin('ravi', 'Home-pass-1')->assertHasErrors('username');
    expect(Auth::guard('selfcare')->check())->toBeFalse();
});

it('keeps the mobile limit when an account ID that looks like a mobile logs in', function () {
    AppSetting::put(AccountNumbers::START, '9876500000');
    Account::factory()->selfcare('Id-pass-1')->create(['account_number' => '9876543210', 'mobile' => '9000000001']);
    Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210']);
    foreach (range(1, 10) as $try) {
        RateLimiter::hit('selfcare-mobile|9876543210', 3600);
    }

    mobileLogin('9876543210', 'Id-pass-1')->assertHasNoErrors();

    expect(RateLimiter::attempts('selfcare-mobile|9876543210'))->toBe(10);
});

it('refuses a mobile shared by more connections than the cap', function () {
    Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210']);
    Account::factory()->count(5)->create(['mobile' => '9876543210']);

    $shared = mobileLogin('9876543210', 'Home-pass-1')->errors()->get('username');
    $unknown = mobileLogin('nobody-here', 'Home-pass-1')->errors()->get('username');

    expect($shared)->toBe($unknown)->and(Auth::guard('selfcare')->check())->toBeFalse();
});

it('opens a mobile shared by as many connections as the cap', function () {
    Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210']);
    Account::factory()->count(4)->create(['mobile' => '9876543210']);

    mobileLogin('9876543210', 'Home-pass-1')->assertHasNoErrors()->assertRedirect(route('selfcare.connections'));
});

it('checks the same number of hashes for any failed mobile login', function (int $withPassword) {
    for ($n = 1; $n <= $withPassword; $n++) {
        Account::factory()->selfcare('Pass-'.$n.'-x1')->create(['mobile' => '9876543210']);
    }
    Account::factory()->create(['mobile' => '9876543210']);
    $checks = 0;
    Hash::partialMock()->shouldReceive('check')->andReturnUsing(function () use (&$checks): bool {
        $checks++;

        return false;
    });

    mobileLogin('9876543210', 'wrong-one')->assertHasErrors('username');

    expect($checks)->toBe(Selfcare::MOBILE_LOGIN_MAX);
})->with([0, 1, 3]);

it('upgrades an old password hash at login and stays logged in', function () {
    $account = Account::factory()->create(['mobile' => '9876543210', 'selfcare_password' => password_hash('Home-pass-1', PASSWORD_BCRYPT, ['cost' => 5])]);

    mobileLogin('9876543210', 'Home-pass-1')->assertHasNoErrors();

    $hash = (string) $account->fresh()->selfcare_password;
    expect(password_get_info($hash)['options']['cost'])->toBe(4)
        ->and(Hash::check('Home-pass-1', $hash))->toBeTrue();
    $this->get(route('selfcare.dashboard'))->assertOk();
});

it('logs a failed mobile try on the connections checked, not on a Terminated username', function () {
    $terminated = Account::factory()->create(['username' => '9876543210', 'status_id' => AccountStatus::Terminated]);
    $home = Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210']);
    $shop = Account::factory()->selfcare('Shop-pass-1')->create(['mobile' => '9876543210']);

    mobileLogin('9876543210', 'wrong-one')->assertHasErrors('username');

    expect(Activity::query()->where('event', 'selfcare-login-failed')->pluck('subject_id')->sort()->values()->all())
        ->toBe([$home->id, $shop->id])
        ->and($terminated->id)->not->toBe($home->id);
});

it('still logs a failed try on a customer who may not use Selfcare when nobody else matches', function () {
    $terminated = Account::factory()->create(['username' => 'ravi', 'status_id' => AccountStatus::Terminated]);

    mobileLogin('ravi', 'wrong-one')->assertHasErrors('username');

    expect(Activity::query()->where('event', 'selfcare-login-failed')->sole()->subject_id)->toBe($terminated->id);
});

it('looks a mobile up by an exact match on the indexed column', function () {
    Account::factory()->selfcare('Home-pass-1')->create(['mobile' => '9876543210']);
    DB::enableQueryLog();

    mobileLogin('+91 98765 43210', 'Home-pass-1')->assertHasNoErrors();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'RIGHT(')))->toBeEmpty();
});

it('keeps an index on the mobile number', function () {
    expect(Schema::hasIndex('accounts', ['mobile']))->toBeTrue();
});
