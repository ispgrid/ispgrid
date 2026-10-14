<?php

use App\Models\Account;
use App\Models\AddressList;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('lists address lists with how many plans and customers use each', function () {
    $list = AddressList::factory()->create(['name' => 'gaming']);
    $list->plans()->attach(Plan::factory()->count(2)->create());
    $list->accounts()->attach(Account::factory()->create());

    Livewire::test('pages::network.address-lists')->assertSeeInOrder(['gaming', '2 plans', '1 customer']);
});

it('adds an address list', function () {
    Livewire::test('pages::network.address-lists')
        ->call('create')
        ->set('name', 'cdn_bypass')
        ->call('save')
        ->assertHasNoErrors();

    expect(AddressList::query()->where('name', 'cdn_bypass')->exists())->toBeTrue();
});

it('refuses a name with characters the router list name cannot have', function (string $name) {
    Livewire::test('pages::network.address-lists')->call('create')->set('name', $name)->call('save')->assertHasErrors(['name']);
})->with(['two words', 'list.name', "it's", '', 'सूची', 'blocked
']);

it('tells staff to restore a deleted list instead of reusing its name', function () {
    AddressList::factory()->trashed()->create(['name' => 'old_list']);

    Livewire::test('pages::network.address-lists')
        ->call('create')
        ->set('name', 'old_list')
        ->call('save')
        ->assertSee('A deleted address list uses this name. Restore it instead.');
});

it('writes a renamed list into the plan and customer rows that send it', function () {
    $list = AddressList::factory()->create(['name' => 'gaming']);
    $plan = Plan::factory()->create();
    $account = Account::factory()->create();
    $list->plans()->attach($plan);
    $list->accounts()->attach($account);
    DB::table('radgroupreply')->insert(['groupname' => $plan->slug, 'attribute' => 'Isp-Plan-Address-List', 'op' => '+=', 'value' => 'gaming']);
    DB::table('radreply')->insert(['username' => $account->username, 'attribute' => 'Mikrotik-Address-List', 'op' => '+=', 'value' => 'gaming']);

    Livewire::test('pages::network.address-lists')
        ->call('edit', $list->id)
        ->set('name', 'low_latency')
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('radgroupreply')->value('value'))->toBe('low_latency')
        ->and(DB::table('radreply')->value('value'))->toBe('low_latency');
});

it('will not delete a list that a plan or customer uses', function () {
    $list = AddressList::factory()->create();
    $list->plans()->attach(Plan::factory()->create());

    Livewire::test('pages::network.address-lists')
        ->call('delete', $list->id)
        ->assertDispatched('toast', variant: 'danger');

    expect($list->fresh()->trashed())->toBeFalse();
});

it('soft deletes an unused list and restores it', function () {
    $list = AddressList::factory()->create();

    Livewire::test('pages::network.address-lists')->call('delete', $list->id);
    expect($list->fresh()->trashed())->toBeTrue();

    Livewire::test('pages::network.address-lists')->call('restore', $list->id);
    expect($list->fresh()->trashed())->toBeFalse();
});

it('links the Network menu to the address lists', function () {
    $this->get(route('dashboard'))->assertSee(route('address-lists.index'));
});
