<?php

use App\Models\Account;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\RadiusSetting;
use App\Models\User;
use Database\Seeders\RadiusSettingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RadiusSettingSeeder::class);
    $this->actingAs(User::factory()->create());
    Process::fake();
});

it('lists pools with how many plans and customers use each', function () {
    $pool = Pool::factory()->create(['name' => 'pppoe']);
    Plan::factory()->create(['pool_id' => $pool->id]);
    Account::factory()->count(2)->create(['pool_id' => $pool->id]);

    Livewire::test('pages::network.pools')->assertSeeInOrder(['pppoe', '1 plan', '2 customers']);
});

it('adds a pool', function () {
    Livewire::test('pages::network.pools')
        ->call('create')
        ->set('name', 'business')
        ->set('description', 'Static business customers')
        ->call('save')
        ->assertHasNoErrors();

    expect(Pool::query()->where('name', 'business')->exists())->toBeTrue();
    Process::assertNothingRan();
});

it('refuses a name a router or FreeRADIUS cannot use', function (string $name) {
    Livewire::test('pages::network.pools')->call('create')->set('name', $name)->call('save')->assertHasErrors(['name']);
})->with(['two words', 'pool|x', "o'brien", '']);

it('tells staff to restore a deleted pool instead of reusing its name', function () {
    Pool::factory()->trashed()->create(['name' => 'old_pool']);

    Livewire::test('pages::network.pools')
        ->call('create')
        ->set('name', 'old_pool')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('A deleted pool uses this name. Restore it instead.');
});

it('writes a renamed pool into the plan and customer rows that send it', function () {
    $pool = Pool::factory()->create(['name' => 'pppoe']);
    $plan = Plan::factory()->create(['pool_id' => $pool->id]);
    $account = Account::factory()->create(['pool_id' => $pool->id]);
    DB::table('radgroupreply')->insert(['groupname' => $plan->slug, 'attribute' => 'Framed-Pool', 'op' => '=', 'value' => 'pppoe']);
    DB::table('radreply')->insert(['username' => $account->username, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => 'pppoe']);

    Livewire::test('pages::network.pools')
        ->call('edit', $pool->id)
        ->set('name', 'pppoe_main')
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('radgroupreply')->value('value'))->toBe('pppoe_main')
        ->and(DB::table('radreply')->value('value'))->toBe('pppoe_main');
});

it('will not rename or delete the disabled pool of the FreeRADIUS settings', function () {
    $pool = Pool::factory()->create(['name' => RadiusSetting::current()->disabled_users_pool]);

    Livewire::test('pages::network.pools')
        ->call('edit', $pool->id)
        ->set('name', 'renamed')
        ->call('save')
        ->assertHasErrors(['name']);

    Livewire::test('pages::network.pools')
        ->call('delete', $pool->id)
        ->assertDispatched('toast', variant: 'danger');

    expect($pool->fresh()->name)->toBe('disabled_pool')
        ->and($pool->fresh()->trashed())->toBeFalse();
});

it('will not delete a pool that a plan or a customer uses', function () {
    $pool = Pool::factory()->create();
    Account::factory()->create(['pool_id' => $pool->id]);

    Livewire::test('pages::network.pools')
        ->call('delete', $pool->id)
        ->assertDispatched('toast', variant: 'danger');

    expect($pool->fresh()->trashed())->toBeFalse();
});

it('soft deletes an unused pool and restores it', function () {
    $pool = Pool::factory()->create();

    Livewire::test('pages::network.pools')->call('delete', $pool->id);
    expect($pool->fresh()->trashed())->toBeTrue();

    Livewire::test('pages::network.pools')->call('restore', $pool->id);
    expect($pool->fresh()->trashed())->toBeFalse();
});

it('links the Network menu to the pools', function () {
    $this->get(route('dashboard'))->assertSee(route('pools.index'));
});
