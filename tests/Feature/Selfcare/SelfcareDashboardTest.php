<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\Plan;
use App\Payments\PayCode;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    turnSelfcareOn();
    AppSetting::put('date_format', 'd/m/Y');
    AppSetting::put('currency_symbol', '₹');
    AppSetting::put('notice_phone', '+91 98765 00000');
    $this->plan = Plan::factory()->create(['name' => 'Gold 100', 'price' => 500, 'validity' => 30]);
});

function customerOn(Plan $plan, array $attributes = []): Account
{
    return Account::factory()->selfcare()->create(['username' => 'kushal', 'fullname' => 'Kushal Example', 'plan_id' => $plan->id, ...$attributes]);
}

it('shows plan, expiry and status', function () {
    actingAsCustomer(customerOn($this->plan, ['expiry' => '2026-11-30 18:00:00', 'status_id' => AccountStatus::Suspended]));

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee('Kushal Example')->assertSee('kushal')->assertSee('Gold 100')
        ->assertSee('30/11/2026 18:00')->assertSee(__('Suspended'));
});

it('links renew to the quick pay page with the amount', function () {
    turnQuickPayOn('7');
    $account = customerOn($this->plan, ['expiry' => now()->addDays(3)]);
    actingAsCustomer($account);

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee(route('pay.code', PayCode::for($account->id)))
        ->assertSee(__('Renew for :amount', ['amount' => '₹590.00'])); // 500 plus 18% GST
});

it('says when renewal opens when nothing is due', function () {
    turnQuickPayOn('7');
    actingAsCustomer(customerOn($this->plan, ['expiry' => '2026-11-30 18:00:00']));

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee(__('Renewal opens on :date', ['date' => '23/11/2026']))
        ->assertDontSee(__('Renew for :amount', ['amount' => '₹500.00']));
});

it('shows the support number when quick pay is closed', function () {
    actingAsCustomer(customerOn($this->plan, ['expiry' => now()->addDays(3)]));

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee(__('To renew, call us: :phone', ['phone' => '+91 98765 00000']))
        ->assertDontSee(route('pay.show'));
});

it('shows only the IP for PPPoE with a static IP', function () {
    $range = IpRange::factory()->pppoe()->create(['network' => '100.64.5.0/24']);
    $ip = Ip::factory()->create(['ip_range_id' => $range->id, 'address' => '100.64.5.20', 'is_used' => true]);
    actingAsCustomer(customerOn($this->plan, ['ip_id' => $ip->id]));

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee('100.64.5.20')->assertDontSee(__('Subnet mask'))->assertDontSee(__('Gateway'));
});

it('shows IP, mask, gateway and DNS for IPoE', function () {
    $range = IpRange::factory()->create(['network' => '10.20.0.0/24', 'gateway' => '10.20.0.1', 'dns1' => '1.1.1.1', 'dns2' => '8.8.8.8']);
    $ip = Ip::factory()->create(['ip_range_id' => $range->id, 'address' => '10.20.0.15', 'is_used' => true]);
    actingAsCustomer(Account::factory()->ipoe()->selfcare()->create(['plan_id' => $this->plan->id, 'ip_id' => $ip->id]));

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSeeInOrder([__('IP address'), '10.20.0.15', __('Subnet mask'), '255.255.255.0', __('Gateway'), '10.20.0.1', __('DNS 1'), '1.1.1.1', __('DNS 2'), '8.8.8.8']);
});

it('leaves out missing values', function () {
    $range = IpRange::factory()->create(['network' => '10.21.0.0/24', 'gateway' => '10.21.0.1', 'dns1' => '1.1.1.1', 'dns2' => null]);
    $ip = Ip::factory()->create(['ip_range_id' => $range->id, 'address' => '10.21.0.15', 'is_used' => true]);
    actingAsCustomer(Account::factory()->ipoe()->selfcare()->create(['plan_id' => $this->plan->id, 'ip_id' => $ip->id]));

    $this->get(route('selfcare.dashboard'))->assertOk()->assertSee(__('DNS 1'))->assertDontSee(__('DNS 2'));
});

it('never shows the NAS name or NAS IP', function () {
    $nas = Nas::factory()->create(['shortname' => 'Tower-NAS-77', 'nasname' => '10.99.99.7']);
    actingAsCustomer(customerOn($this->plan, ['nas_id' => $nas->id]));

    $this->get(route('selfcare.dashboard'))->assertOk()->assertDontSee('Tower-NAS-77')->assertDontSee('10.99.99.7');
});
