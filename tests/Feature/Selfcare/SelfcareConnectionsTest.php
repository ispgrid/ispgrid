<?php

use App\Actions\Customers\SetSelfcarePassword;
use App\Http\Middleware\SelfcareSession;
use App\Models\Account;
use App\Models\Address;
use App\Models\AppSetting;
use App\Models\SelfcareAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    turnSelfcareOn(pppoePasswordChange: true);
    AppSetting::put('phone_digits', '10');
    $this->home = Account::factory()->selfcare('Home-pass-1')->create(['fullname' => 'Ravi Home', 'mobile' => '9876543210', 'account_number' => 'LINK-1']);
    $this->shop = Account::factory()->create(['fullname' => 'Ravi Shop', 'mobile' => '9876543210', 'username' => 'ravishop',
        'address_id' => Address::query()->create(['line1' => '12 Market Road', 'city' => 'Pune'])->id]);
    $this->other = Account::factory()->selfcare('Other-pass-1')->create(['fullname' => 'Someone Else', 'mobile' => '9000000000']);
});

/**
 * Logs in by mobile the way the login page does.
 */
function loginByMobile(Account $account, string $mobile = '9876543210'): void
{
    $customer = SelfcareAccount::query()->findOrFail($account->id);
    Auth::guard('selfcare')->login($customer);
    SelfcareSession::remember($customer, $mobile);
}

it('lists every eligible connection on the mobile with name, address, username and account ID', function () {
    loginByMobile($this->home);

    $this->get(route('selfcare.connections'))->assertOk()
        ->assertSee('Ravi Home')->assertSee('LINK-1')->assertSee($this->home->username)
        ->assertSee('Ravi Shop')->assertSee('ravishop')->assertSee('12 Market Road')
        ->assertDontSee('Someone Else');
});

it('opens the chosen connection and logs selfcare-switch', function () {
    loginByMobile($this->home);

    Livewire::test('pages::selfcare.connections')->call('open', $this->shop->id)->assertRedirect(route('selfcare.dashboard'));

    expect(Auth::guard('selfcare')->id())->toBe($this->shop->id)
        ->and(Activity::query()->where('event', 'selfcare-switch')->sole()->subject_id)->toBe($this->shop->id)
        ->and(Activity::query()->where('event', 'selfcare-switch')->sole()->causer_id)->toBe($this->home->id)
        ->and($this->shop->fresh()->selfcare_last_login_at)->not->toBeNull();
    $this->get(route('selfcare.dashboard'))->assertOk()->assertSee('Ravi Shop');
});

it('refuses a connection on another mobile', function () {
    loginByMobile($this->home);

    Livewire::test('pages::selfcare.connections')->call('open', $this->other->id)->assertNotFound();
    expect(Auth::guard('selfcare')->id())->toBe($this->home->id);
});

it('offers only the open connection in a username session', function () {
    loginByMobile($this->home, '');
    session()->forget(SelfcareSession::MOBILE);

    Livewire::test('pages::selfcare.connections')->call('open', $this->shop->id)->assertNotFound();
});

it('logs out when staff set a new password on the login account', function () {
    loginByMobile($this->home);
    Livewire::test('pages::selfcare.connections')->call('open', $this->shop->id);

    app(SetSelfcarePassword::class)->handle($this->home, 'New-pass-77');

    $this->get(route('selfcare.dashboard'))->assertRedirect(route('selfcare.login'));
});

it('logs out of a connection whose mobile changed', function () {
    loginByMobile($this->home);
    Livewire::test('pages::selfcare.connections')->call('open', $this->shop->id);

    $this->shop->update(['mobile' => '9555555555']);

    $this->get(route('selfcare.dashboard'))->assertRedirect(route('selfcare.login'));
});

it('logs out when the login account mobile changed', function () {
    loginByMobile($this->home);
    Livewire::test('pages::selfcare.connections')->call('open', $this->shop->id);

    $this->home->update(['mobile' => '9555555555']);

    $this->get(route('selfcare.dashboard'))->assertRedirect(route('selfcare.login'));
});

it('asks the login account to change a staff-set password first', function () {
    $this->home->forceFill(['selfcare_must_change' => true])->save();
    loginByMobile($this->home);

    $this->get(route('selfcare.connections'))->assertRedirect(route('selfcare.password'));
});

it('changes the login account password from another connection', function () {
    loginByMobile($this->home);
    Livewire::test('pages::selfcare.connections')->call('open', $this->shop->id);

    Livewire::test('pages::selfcare.password')
        ->set('current_password', 'Home-pass-1')->set('password', 'Brand-new-pass-9')->set('password_confirmation', 'Brand-new-pass-9')
        ->call('save')->assertHasNoErrors();

    expect(Hash::check('Brand-new-pass-9', $this->home->fresh()->selfcare_password))->toBeTrue()
        ->and($this->shop->fresh()->selfcare_password)->toBeNull();
    $this->get(route('selfcare.dashboard'))->assertOk()->assertSee('Ravi Shop');
});

it('confirms a PPPoE change with the password the customer logged in with', function () {
    loginByMobile($this->home);
    Livewire::test('pages::selfcare.connections')->call('open', $this->shop->id);

    Livewire::test('pages::selfcare.pppoe-password')
        ->set('selfcare_password', 'Home-pass-1')->set('password', 'shop-pppoe-9')->set('password_confirmation', 'shop-pppoe-9')
        ->call('save')->assertHasNoErrors();

    expect($this->shop->fresh()->password)->toBe('shop-pppoe-9');
});

it('shows Switch connection only with more than one connection', function () {
    loginByMobile($this->home);
    $this->get(route('selfcare.dashboard'))->assertSee('Switch connection');

    $this->shop->update(['mobile' => '9555555555']);
    $this->get(route('selfcare.dashboard'))->assertOk()->assertDontSee('Switch connection');
});

it('keeps a session from before this change logged in', function () {
    actingAsCustomer($this->other);
    session()->forget([SelfcareSession::LOGIN_ID, SelfcareSession::MOBILE]);

    $this->get(route('selfcare.dashboard'))->assertOk()->assertSee('Someone Else')->assertDontSee('Switch connection');
});

it('offers only the open connection once the mobile is shared by more than the cap', function () {
    loginByMobile($this->home);
    Account::factory()->count(4)->create(['mobile' => '9876543210']);

    Livewire::test('pages::selfcare.connections')->call('open', $this->shop->id)->assertNotFound();
    expect(SelfcareSession::connections()->pluck('id')->all())->toBe([$this->home->id]);
});
