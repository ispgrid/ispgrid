<?php

use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\Radacct;
use App\Models\Radpostauth;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    turnSelfcareOn();
    AppSetting::put('date_format', 'd/m/Y');
    $this->account = Account::factory()->selfcare()->create(['username' => 'kushal']);
    actingAsCustomer($this->account);
});

it('shows this period and the last 30 days of usage', function () {
    usagePeriod('kushal', 3 * 1024 ** 3);
    usageDay('kushal', '2026-11-04', download: 2 * 1024 ** 3, upload: 512 * 1024 ** 2);
    usageDay('kushal', '2026-10-01', download: 7 * 1024 ** 3);

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee(__('Usage'))
        ->assertSee('3.00 GB')
        ->assertSeeInOrder(['04/11/2026', '2.00 GB', '512.0 MB'])
        ->assertDontSee('01/10/2026');
});

it('hides usage when there is no data', function () {
    $this->get(route('selfcare.dashboard'))->assertOk()->assertDontSee(__('Data used since the last recharge'));
});

it('shows the open session with IP and uptime', function () {
    Radacct::factory()->create(['username' => 'kushal', 'acctstarttime' => now()->subMinutes(125), 'framedipaddress' => '100.64.1.9', 'acctinputoctets' => 1024 ** 2, 'acctoutputoctets' => 9 * 1024 ** 2]);
    Radacct::factory()->closed()->create(['username' => 'kushal', 'framedipaddress' => '100.64.1.200']);

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee(__('Online now'))
        ->assertSee('100.64.1.9')->assertSee('2 h 05 min')->assertSee('10.0 MB')
        ->assertDontSee('100.64.1.200');
});

it('lists the last 10 logins with results in words', function () {
    foreach (range(1, 11) as $minutes) {
        Radpostauth::factory()->create(['username' => 'kushal', 'authdate' => now()->subMinutes($minutes * 10)]);
    }
    Radpostauth::factory()->rejected('Wrong Password')->create(['username' => 'kushal', 'authdate' => now()->subMinute()]);

    $response = $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee(__('Recent logins'))
        ->assertSeeInOrder([now()->subMinute()->format('d/m/Y H:i'), __('Rejected'), __('Accepted')])
        ->assertDontSee('Wrong Password')
        ->assertDontSee(now()->subMinutes(100)->format('d/m/Y H:i'));

    expect(substr_count($response->getContent(), __('Accepted')))->toBe(9);
});

it('shows only the logged-in customer\'s rows', function () {
    Radacct::factory()->create(['username' => 'someone', 'framedipaddress' => '100.64.7.7']);
    Radpostauth::factory()->rejected('Expired')->create(['username' => 'someone', 'authdate' => now()->subMinutes(3)]);
    usageDay('someone', '2026-11-04', download: 5 * 1024 ** 3);

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertDontSee('100.64.7.7')->assertDontSee(__('Recent logins'))->assertDontSee('5.00 GB');
});

it('never shows the NAS IP', function () {
    Nas::factory()->create(['shortname' => 'Tower-NAS-77', 'nasname' => '10.77.0.1']);
    Radacct::factory()->create(['username' => 'kushal', 'nasipaddress' => '10.77.0.1']);
    Radpostauth::factory()->create(['username' => 'kushal', 'nasipaddress' => '10.77.0.1']);

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee(__('Online now'))->assertDontSee('10.77.0.1')->assertDontSee('Tower-NAS-77');
});
