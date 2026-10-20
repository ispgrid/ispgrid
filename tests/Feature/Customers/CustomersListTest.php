<?php

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Radacct;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists customers with their type, plan, status and expiry', function () {
    AppSetting::put('date_format', 'd/m/Y');
    Account::factory()->create([
        'username' => 'alice', 'fullname' => 'Alice Rao', 'expiry' => '2030-01-31 18:00:00',
        'plan_id' => Plan::factory()->create(['name' => 'Home 25'])->id,
    ]);

    $component = Livewire::test('pages::customers.index')->assertSee('alice')->assertSee('31/01/2030 18:00');

    // The filter selects also show plan, type and status names, so check the row itself.
    expect($component->instance()->rows[0])->toMatchArray([
        'username' => 'alice', 'name' => 'Alice Rao', 'login' => 'alice', 'type' => 'PPPoE', 'plan' => 'Home 25', 'status' => 'Active',
    ]);
});

it('shows Never for a customer without an expiry', function () {
    Account::factory()->create(['expiry' => null]);

    Livewire::test('pages::customers.index')->assertSee('Never');
});

it('finds a customer by username, name, mobile, email or IP', function (string $search) {
    Account::factory()->create([
        'username' => 'alice', 'fullname' => 'Alice Rao', 'mobile' => '9876543210', 'email' => 'alice@example.com',
        'ip_id' => Ip::factory()->create(['address' => '10.0.0.7'])->id,
    ]);
    Account::factory()->create(['username' => 'bob', 'fullname' => 'Bob Das', 'mobile' => '9000000000', 'email' => null]);

    Livewire::test('pages::customers.index')
        ->set('search', $search)
        ->assertSee('alice')
        ->assertDontSee('Bob Das');
})->with(['ali', 'Rao', '98765', 'alice@example', '10.0.0.7']);

it('filters by status, plan, type, router and connection type', function (string $filter) {
    $plan = Plan::factory()->create();
    $router = Nas::factory()->create();
    $type = ConnectionType::factory()->create();
    Account::factory()->suspended()->ipoe()->create([
        'username' => 'matching', 'plan_id' => $plan->id, 'nas_id' => $router->id, 'connection_type_id' => $type->id,
    ]);
    Account::factory()->create(['username' => 'unrelated']);

    $value = match ($filter) {
        'status' => (string) AccountStatus::Suspended->value,
        'plan' => (string) $plan->id,
        'type' => ServiceType::Ipoe->value,
        'router' => (string) $router->id,
        'connection' => (string) $type->id,
    };

    Livewire::test('pages::customers.index')
        ->set($filter, $value)
        ->assertSee('matching')
        ->assertDontSee('unrelated');
})->with(['status', 'plan', 'type', 'router', 'connection']);

it('shows the customers who expire within seven days', function () {
    Account::factory()->create(['username' => 'soon', 'expiry' => now()->addDays(3)]);
    Account::factory()->create(['username' => 'later', 'expiry' => now()->addDays(20)]);
    Account::factory()->expired()->create(['username' => 'gone']);

    Livewire::test('pages::customers.index')
        ->set('expiring', '1')
        ->assertSee('soon')
        ->assertDontSee('later')
        ->assertDontSee('gone');
});

it('shows deleted customers only under the Deleted filter', function () {
    Account::factory()->create(['username' => 'present_user']);
    Account::factory()->trashed()->create(['username' => 'removed_user']);

    Livewire::test('pages::customers.index')->assertSee('present_user')->assertDontSee('removed_user');
    Livewire::test('pages::customers.index')->set('status', 'deleted')->assertSee('removed_user')->assertDontSee('present_user');
});

it('shows 50 customers per page', function () {
    Account::factory()->count(51)->sequence(fn ($sequence) => ['username' => sprintf('user%03d', $sequence->index)])->create();

    Livewire::test('pages::customers.index')->assertSee('user049')->assertDontSee('user050');
});

it('links each customer to their page', function () {
    $account = Account::factory()->create();

    Livewire::test('pages::customers.index')->assertSee(route('customers.show', $account->id));
});

it('lists only expired customers when asked', function () {
    $this->travelTo(Carbon::parse('2026-10-10 12:00:00'));
    Account::factory()->create(['username' => 'lapsed', 'expiry' => '2026-10-01 00:00:00']);
    Account::factory()->create(['username' => 'paidup', 'expiry' => '2026-11-01 00:00:00']);

    Livewire::test('pages::customers.index')
        ->set('expired', '1')
        ->assertSee('lapsed')
        ->assertDontSee('paidup');
});

it('offers the Hotspot type filter only while Hotspot is turned on', function () {
    expect(array_keys(Livewire::test('pages::customers.index')->instance()->types))->toBe(['PPP', 'IPOE']);

    turnHotspotOn();

    expect(array_keys(Livewire::test('pages::customers.index')->instance()->types))->toBe(['PPP', 'HOTSPOT', 'IPOE']);
});

it('shows a validity waiting for the first login as not started, never as expired', function () {
    Account::factory()->hotspotPending(180)->create(['username' => 'waiting1']);

    Livewire::test('pages::customers.index')->assertSee('Not started');
    Livewire::test('pages::customers.index')->set('expired', '1')->assertDontSee('waiting1');
    Livewire::test('pages::customers.index')->set('expiring', '1')->assertDontSee('waiting1');
});

/**
 * The usernames the list shows with these filters (from the address, as a dashboard link opens it).
 *
 * @param  array<string, string>  $filters
 * @return list<string>
 */
function listedUsernames(array $filters): array
{
    $component = Livewire::withQueryParams($filters)->test('pages::customers.index');

    return collect($component->instance()->rows)->pluck('username')->sort()->values()->all();
}

it('narrows the list the way each dashboard number counts', function (array $filters, array $expected) {
    $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
    $plan = Plan::factory()->create();
    Account::factory()->create(['username' => 'today_end', 'expiry' => '2026-10-20 23:00:00', 'created_at' => '2026-09-01 10:00:00']);
    Account::factory()->create(['username' => 'week_end', 'expiry' => '2026-10-26 10:00:00', 'auto_renew' => true, 'created_at' => '2026-09-01 10:00:00']);
    $booked = Account::factory()->create(['username' => 'booked', 'expiry' => '2026-10-25 10:00:00', 'created_at' => '2026-09-01 10:00:00']);
    AdvanceRenewal::factory()->for($booked)->create(['plan_id' => $plan->id]);
    Account::factory()->create(['username' => 'lapsed_recent', 'expiry' => '2026-10-15 10:00:00', 'created_at' => '2026-09-01 10:00:00']);
    Account::factory()->create(['username' => 'lapsed_old', 'expiry' => '2026-09-01 10:00:00', 'created_at' => '2026-09-01 10:00:00']);
    Account::factory()->inactive()->create(['username' => 'new_today', 'expiry' => null, 'created_at' => '2026-10-20 09:00:00']);
    Account::factory()->suspended()->create(['username' => 'paused', 'expiry' => '2026-12-01 00:00:00', 'created_at' => '2026-10-02 09:00:00',
        'ip_id' => Ip::factory()->create()->id]);
    $renewed = Account::factory()->create(['username' => 'renewed', 'expiry' => '2026-11-19 12:00:00', 'created_at' => '2026-09-01 10:00:00']);
    Recharge::factory()->for($renewed)->create(['created_at' => '2026-10-20 08:00:00']);
    Account::factory()->create(['username' => 'ended', 'status_id' => AccountStatus::Terminated, 'expiry' => '2026-12-01 00:00:00', 'created_at' => '2026-09-01 10:00:00']);

    expect(listedUsernames($filters))->toBe($expected);
})->with([
    'expiring within 7 days (old links)' => [['expiring' => '1'], ['booked', 'today_end', 'week_end']],
    'expiring today' => [['expiring' => 'today'], ['today_end']],
    'without a renewal' => [['expiring' => '1', 'norenewal' => '1'], ['today_end']],
    'expired (old links)' => [['expired' => '1'], ['lapsed_old', 'lapsed_recent']],
    'expired in the last 7 days' => [['expired' => '7'], ['lapsed_recent']],
    'disabled' => [['disabled' => '1'], ['new_today', 'paused']],
    'new today' => [['created' => 'today'], ['new_today']],
    'new this month' => [['created' => 'month'], ['new_today', 'paused']],
    'renewed today' => [['renewed' => '1'], ['renewed']],
    'static IP' => [['staticip' => '1'], ['paused']],
]);

it('shows a green dot for a customer who is online, from one query for the page', function () {
    Account::factory()->create(['username' => 'online_one']);
    Account::factory()->create(['username' => 'offline_one']);
    Radacct::factory()->create(['username' => 'online_one']);
    Radacct::factory()->closed()->create(['username' => 'offline_one']);

    DB::enableQueryLog();
    $component = Livewire::test('pages::customers.index');
    $radacctQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'radacct'))->count();

    $rows = collect($component->instance()->rows)->keyBy('username');
    expect($rows['online_one']['online'])->toBeTrue()
        ->and($rows['offline_one']['online'])->toBeFalse()
        ->and($radacctQueries)->toBe(1);
    $component->assertSeeHtml('title="Online"');
});

it('names a filter that has no control of its own and lets staff clear it', function () {
    Livewire::withQueryParams(['norenewal' => '1'])->test('pages::customers.index')
        ->assertSee('Without a renewal')
        ->call('clearExtraFilters')
        ->assertSet('norenewal', false)
        ->assertDontSee('Without a renewal');
});

it('shows the full name as the link with the username under it, or the username alone', function () {
    Account::factory()->create(['username' => 'ramesh_s4', 'fullname' => 'Ramesh Kumar']);
    Account::factory()->create(['username' => 'voucher1', 'fullname' => '  ']);

    $rows = collect(Livewire::test('pages::customers.index')->instance()->rows)->keyBy('username');

    expect($rows['ramesh_s4'])->name->toBe('Ramesh Kumar')->login->toBe('ramesh_s4')
        ->and($rows['voucher1'])->name->toBe('voucher1')->login->toBeNull();
});
