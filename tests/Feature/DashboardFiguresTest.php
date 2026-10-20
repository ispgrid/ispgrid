<?php

use App\Enums\AccountStatus;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\Ip;
use App\Models\Plan;
use App\Models\Radacct;
use App\Models\Radpostauth;
use App\Models\Recharge;
use App\Models\User;
use App\Support\Dashboard\TodayFigures;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-10 12:00:00'));
    $this->actingAs(User::factory()->create());
});

/**
 * The dashboard numbers by label, as this staff member sees them.
 *
 * @return array<string, array{value: int, href: ?string}>
 */
function todayFigures(User $user): array
{
    return collect(TodayFigures::cards($user))
        ->mapWithKeys(fn (array $card): array => [$card['label'] => ['value' => $card['value'], 'href' => $card['href']]])
        ->all();
}

it('counts each number of the day and opens the list it counts', function () {
    $plan = Plan::factory()->create();
    Radacct::factory()->count(2)->create(['username' => 'alice']);
    Radacct::factory()->create(['username' => 'bob']);
    Radacct::factory()->closed()->create(['username' => 'carol']);
    Account::factory()->create(['expiry' => '2026-10-10 12:00:00', 'created_at' => '2026-09-01 10:00:00']);
    Account::factory()->create(['expiry' => '2026-10-10 22:00:00', 'auto_renew' => true, 'created_at' => '2026-09-01 10:00:00']);
    $booked = Account::factory()->create(['expiry' => '2026-10-17 12:00:00', 'created_at' => '2026-09-01 10:00:00', 'ip_id' => Ip::factory()->create()->id]);
    AdvanceRenewal::factory()->for($booked)->create(['plan_id' => $plan->id]);
    Account::factory()->create(['expiry' => '2026-10-17 12:01:00', 'created_at' => '2026-09-01 10:00:00']);
    Account::factory()->create(['expiry' => '2026-10-03 12:00:00', 'created_at' => '2026-09-01 10:00:00']);
    Account::factory()->create(['expiry' => '2026-10-03 11:59:00', 'created_at' => '2026-09-01 10:00:00']);
    $renewed = Account::factory()->create(['expiry' => '2026-12-01 00:00:00', 'created_at' => '2026-09-01 10:00:00']);
    Recharge::factory()->for($renewed)->count(2)->create(['created_at' => '2026-10-10 08:00:00']);
    Recharge::factory()->for(Account::factory()->create(['created_at' => '2026-09-01 10:00:00']))->create(['created_at' => '2026-10-09 23:59:00']);
    Account::factory()->inactive()->create(['expiry' => '2026-10-11 00:00:00', 'created_at' => '2026-10-10 09:00:00']);
    Account::factory()->suspended()->create(['expiry' => '2026-10-01 00:00:00', 'created_at' => '2026-10-01 00:00:00']);
    Account::factory()->create(['status_id' => AccountStatus::Terminated, 'expiry' => '2026-10-11 00:00:00', 'created_at' => '2026-09-30 23:59:00']);
    Account::factory()->trashed()->create(['expiry' => '2026-10-11 00:00:00', 'created_at' => '2026-10-10 09:00:00']);

    $figures = todayFigures(User::factory()->create());

    expect(array_keys($figures))->toBe(['Total', 'Online now', 'Active', 'Disabled', 'Expiring today', 'Expiring within 7 days',
        'Without a renewal', 'Expired in the last 7 days', 'Renewed today', 'New today', 'New this month', 'Static IP expiring within 7 days'])
        ->and(array_map(fn (array $figure): int => $figure['value'], $figures))->toBe([
            'Total' => 11, 'Online now' => 2, 'Active' => 8, 'Disabled' => 2, 'Expiring today' => 2, 'Expiring within 7 days' => 3,
            'Without a renewal' => 1, 'Expired in the last 7 days' => 1, 'Renewed today' => 1, 'New today' => 1, 'New this month' => 2,
            'Static IP expiring within 7 days' => 1,
        ])
        ->and($figures['Online now']['href'])->toBe(route('online.index'))
        ->and($figures['Total']['href'])->toBe(route('customers.index'))
        ->and($figures['Disabled']['href'])->toBe(route('customers.index', ['disabled' => 1]))
        ->and($figures['Expiring today']['href'])->toBe(route('customers.index', ['status' => 1, 'expiring' => 'today']))
        ->and($figures['Without a renewal']['href'])->toBe(route('customers.index', ['status' => 1, 'expiring' => 1, 'norenewal' => 1]))
        ->and($figures['Expired in the last 7 days']['href'])->toBe(route('customers.index', ['status' => 1, 'expired' => 7]))
        ->and($figures['Static IP expiring within 7 days']['href'])->toBe(route('customers.index', ['status' => 1, 'expiring' => 1, 'staticip' => 1]));
});

it('shows numbers that the list behind each one agrees with', function () {
    $plan = Plan::factory()->create();
    Account::factory()->create(['username' => 'soon', 'expiry' => '2026-10-12 00:00:00']);
    AdvanceRenewal::factory()->for(Account::factory()->create(['username' => 'booked', 'expiry' => '2026-10-13 00:00:00']))->create(['plan_id' => $plan->id]);
    Account::factory()->suspended()->create(['username' => 'paused', 'expiry' => '2026-10-12 00:00:00']);
    Account::factory()->create(['username' => 'lapsed', 'expiry' => '2026-10-08 00:00:00']);

    foreach (TodayFigures::cards(User::factory()->create()) as $card) {
        if ($card['href'] === null || ! str_contains($card['href'], '/customers')) {
            continue;
        }
        parse_str((string) parse_url($card['href'], PHP_URL_QUERY), $filters);
        $listed = Livewire::withQueryParams($filters)->test('pages::customers.index')->instance()->accounts->total();

        expect($listed)->toBe($card['value'], $card['label']);
    }
});

it('keeps the numbers but drops the links a role cannot open', function () {
    Account::factory()->create();
    $figures = todayFigures(staffHolding(Permission::ViewPlans));

    expect($figures['Total']['value'])->toBe(1)
        ->and(array_filter(array_column($figures, 'href')))->toBe([])
        ->and(todayFigures(staffHolding(Permission::ViewOnlineSessions))['Online now']['href'])->toBe(route('online.index'));
});

it('shows the numbers as links on the dashboard', function () {
    Account::factory()->create(['expiry' => '2026-10-12 00:00:00']);

    Livewire::test('pages::dashboard')
        ->assertSee('Expiring within 7 days')
        ->assertSee(route('customers.index', ['status' => 1, 'expiring' => 1]));
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

    $figures = todayFigures(User::factory()->create());

    expect($figures['Active']['value'])->toBe(1)
        ->and($figures['Expiring within 7 days']['value'])->toBe(0)
        ->and($figures['Expired in the last 7 days']['value'])->toBe(0);
});
