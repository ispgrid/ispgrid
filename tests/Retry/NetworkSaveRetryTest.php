<?php

use App\Models\Account;
use App\Models\AddressList;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/**
 * The first statement starting with $statement hits a deadlock once it ran, as two staff saving at the same moment
 * can; the save's transaction rolls back and tries again.
 */
function deadlockOnceAfter(string $statement): void
{
    $failed = false;

    DB::listen(function (QueryExecuted $query) use (&$failed, $statement): void {
        if (! $failed && str_starts_with($query->sql, $statement)) {
            $failed = true;

            throw new QueryException('mysql', $query->sql, [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
        }
    });
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('writes a router\'s new IP into Isp-Nas when the save is retried after a deadlock', function () {
    Process::fake();
    $router = Nas::factory()->create(['nasname' => '10.0.0.1']);
    $bound = Account::factory()->create(['nas_id' => $router->id]);
    DB::table('radcheck')->insert(['username' => $bound->username, 'attribute' => 'Isp-Nas', 'op' => ':=', 'value' => '10.0.0.1']);
    deadlockOnceAfter('update `radcheck`');

    Livewire::test('pages::network.routers')->call('edit', $router->id)->set('nasname', '10.0.0.2')->call('save')->assertHasNoErrors();

    expect($router->fresh()->nasname)->toBe('10.0.0.2')
        ->and(DB::table('radcheck')->where('username', $bound->username)->value('value'))->toBe('10.0.0.2');
});

it('writes a renamed pool into the plan and customer rows when the save is retried after a deadlock', function () {
    $pool = Pool::factory()->create(['name' => 'pppoe']);
    $plan = Plan::factory()->create(['pool_id' => $pool->id]);
    $account = Account::factory()->create(['pool_id' => $pool->id]);
    DB::table('radgroupreply')->insert(['groupname' => $plan->slug, 'attribute' => 'Framed-Pool', 'op' => '=', 'value' => 'pppoe']);
    DB::table('radreply')->insert(['username' => $account->username, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => 'pppoe']);
    deadlockOnceAfter('update `radreply`');

    Livewire::test('pages::network.pools')->call('edit', $pool->id)->set('name', 'pppoe_main')->call('save')->assertHasNoErrors();

    expect(DB::table('radgroupreply')->where('groupname', $plan->slug)->value('value'))->toBe('pppoe_main')
        ->and(DB::table('radreply')->where('username', $account->username)->value('value'))->toBe('pppoe_main');
});

it('writes a renamed address list into the plan and customer rows when the save is retried after a deadlock', function () {
    $list = AddressList::factory()->create(['name' => 'gold']);
    $plan = Plan::factory()->create();
    $account = Account::factory()->create();
    $list->plans()->attach($plan);
    $list->accounts()->attach($account);
    DB::table('radgroupreply')->insert(['groupname' => $plan->slug, 'attribute' => 'Isp-Plan-Address-List', 'op' => '=', 'value' => 'gold']);
    DB::table('radreply')->insert(['username' => $account->username, 'attribute' => 'Mikrotik-Address-List', 'op' => ':=', 'value' => 'gold']);
    deadlockOnceAfter('update `radreply`');

    Livewire::test('pages::network.address-lists')->call('edit', $list->id)->set('name', 'gold_plus')->call('save')->assertHasNoErrors();

    expect(DB::table('radgroupreply')->where('groupname', $plan->slug)->value('value'))->toBe('gold_plus')
        ->and(DB::table('radreply')->where('username', $account->username)->value('value'))->toBe('gold_plus');
});

it('swaps the gateway in the inventory when the save is retried after a deadlock', function () {
    Livewire::test('pages::network.ip-ranges')->call('create')
        ->set('name', 'Customers')->set('network', '10.20.0.0/29')->set('gateway', '10.20.0.1')->call('save');
    $range = IpRange::query()->sole();
    deadlockOnceAfter('update `ips`');

    Livewire::test('pages::network.ip-ranges')->call('edit', $range->id)->set('gateway', '10.20.0.6')->call('save')->assertHasNoErrors();

    expect($range->fresh()->gateway)->toBe('10.20.0.6')
        ->and(Ip::query()->pluck('address')->all())->toContain('10.20.0.1')->not->toContain('10.20.0.6');
});
