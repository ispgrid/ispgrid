<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Radacct;
use App\Models\Radpostauth;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 12:00:00'));
    $this->actingAs(User::factory()->create());
});

it('counts who is online, active, expiring within 7 days and expired', function () {
    Radacct::factory()->count(2)->create(['username' => 'alice']);
    Radacct::factory()->create(['username' => 'bob']);
    Radacct::factory()->closed()->create(['username' => 'carol']);
    Account::factory()->create(['status_id' => AccountStatus::Active, 'expiry' => '2026-12-01 00:00:00']);
    Account::factory()->create(['status_id' => AccountStatus::Active, 'expiry' => '2026-10-15 00:00:00']);
    Account::factory()->count(2)->create(['status_id' => AccountStatus::Active, 'expiry' => '2026-10-01 00:00:00']);
    Account::factory()->create(['status_id' => AccountStatus::Inactive, 'expiry' => '2026-10-01 00:00:00']);
    Account::factory()->trashed()->create(['status_id' => AccountStatus::Active]);

    Livewire::test('pages::dashboard')
        ->assertSet('figures', ['online' => 2, 'active' => 4, 'expiring' => 1, 'expired' => 2])
        ->assertSee(route('online.index'))
        ->assertSee(route('customers.index', ['status' => 1, 'expiring' => 1]))
        ->assertSee(route('customers.index', ['status' => 1, 'expired' => 1]));
});

it('lists the latest failed logins', function () {
    Radpostauth::factory()->rejected('Account is suspended')->create(['username' => 'alice']);
    Radpostauth::factory()->dropped()->create(['username' => 'router-probe']);
    Radpostauth::factory()->create(['username' => 'bob']);

    Livewire::test('pages::dashboard')
        ->assertSee('Account is suspended')
        ->assertSee('router-probe')
        ->assertDontSee('bob')
        ->assertSee(route('login-log.index', ['result' => 'rejected']), false);
});

it('never counts a customer waiting for their first login as expiring or expired', function () {
    Account::factory()->hotspotPending(180)->create(['status_id' => AccountStatus::Active]);

    Livewire::test('pages::dashboard')->assertSet('figures', ['online' => 0, 'active' => 1, 'expiring' => 0, 'expired' => 0]);
});
