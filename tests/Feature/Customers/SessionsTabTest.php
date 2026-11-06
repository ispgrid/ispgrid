<?php

use App\Enums\DisconnectReason;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\Radacct;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
    AppSetting::put('date_format', 'd/m/Y');
    $this->staff = User::factory()->create(['name' => 'Asha']);
    $this->actingAs($this->staff);
    Nas::factory()->create(['nasname' => '10.0.0.1', 'shortname' => 'core']);
    $this->account = Account::factory()->create(['username' => 'shubham']);
});

function endedSession(array $values): Radacct
{
    return Radacct::factory()->create(['username' => 'shubham', 'nasipaddress' => '10.0.0.1', ...$values]);
}

it('is a tab on the customer page', function () {
    $this->get(route('customers.show', [$this->account, 'tab' => 'sessions']))
        ->assertOk()->assertSee(__('Sessions'))->assertSee(__('Why it ended'));
});

it('lists the sessions of the month with its totals and why each ended', function () {
    endedSession(['acctstarttime' => '2026-10-23 10:00:00', 'acctstoptime' => '2026-10-23 12:00:00', 'acctsessiontime' => 7200,
        'acctinputoctets' => 1_000_000_000, 'acctoutputoctets' => 3_000_000_000, 'acctterminatecause' => 'Lost-Carrier', 'nasportid' => 'vlan8']);
    endedSession(['acctstarttime' => '2026-10-22 08:00:00', 'acctstoptime' => '2026-10-22 09:00:00', 'acctsessiontime' => 3600, 'acctterminatecause' => 'User-Request']);
    endedSession(['acctstarttime' => '2026-09-30 08:00:00', 'acctstoptime' => '2026-09-30 09:00:00', 'acctterminatecause' => 'Idle-Timeout']);

    $tab = Livewire::test('pages::customers.sessions-tab', ['accountId' => $this->account->id]);

    $tab->assertSeeInOrder(['23/10/2026 10:00', 'vlan8', 'Line lost (cable, ONU or radio)', '22/10/2026 08:00', "Customer's router ended it"])
        ->assertDontSee('Idle too long');
    expect($tab->instance()->totals['time'])->toBe('3 h');

    $tab->set('month', '2026-09')->assertSee('Idle too long')->assertDontSee('Line lost');
});

it('names the change and who made it for a session ISPGrid ended', function () {
    $session = endedSession(['acctstarttime' => '2026-10-23 15:00:00', 'acctstoptime' => '2026-10-23 15:42:16', 'acctterminatecause' => 'NAS-Request']);
    activity()->performedOn($this->account)->causedBy($this->staff)->event('session-disconnected')
        ->withChanges(['old' => [], 'attributes' => ['reason' => DisconnectReason::PlanChanged->value, 'session' => $session->radacctid]])
        ->log('session-disconnected');

    Livewire::test('pages::customers.sessions-tab', ['accountId' => $this->account->id])
        ->assertSee('Ended by the NAS')
        ->assertSee('Disconnected by ISPGrid: plan changed (Asha)');
});

it('names the Disconnect button for a session staff ended by hand', function () {
    $this->travelTo(Carbon::parse('2026-10-23 15:42:10'));
    activity()->performedOn($this->account)->causedBy($this->staff)->event('disconnected')->log('disconnected');
    $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
    endedSession(['acctstarttime' => '2026-10-23 15:00:00', 'acctstoptime' => '2026-10-23 15:42:16', 'acctterminatecause' => 'NAS-Request']);

    Livewire::test('pages::customers.sessions-tab', ['accountId' => $this->account->id])
        ->assertSee('Disconnected by ISPGrid: Disconnect button (Asha)');
});

it('refuses staff who may not see the customer', function () {
    $this->actingAs(staffLacking(Permission::ViewCustomers));

    Livewire::test('pages::customers.sessions-tab', ['accountId' => $this->account->id])->assertForbidden();
});
