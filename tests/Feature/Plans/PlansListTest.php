<?php

use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->actingAs(User::factory()->create());
});

it('opens the plans page from the menu', function () {
    $this->get(route('dashboard'))->assertSee(route('plans.index'));
    $this->get(route('plans.index'))->assertOk()->assertSee(route('plans.create'));
});

it('lists plans with their speed, validity, price and live customers', function () {
    $plan = Plan::factory()->create(['name' => 'Home 25', 'upload' => '10M', 'download' => '25M', 'validity' => 30, 'price' => 1499]);
    Account::factory()->count(2)->create(['plan_id' => $plan->id]);
    Account::factory()->trashed()->create(['plan_id' => $plan->id]);

    Livewire::test('pages::plans.index')->assertSeeInOrder(['Home 25', '10M/25M', '30 days', '₹1,499.00', '2 customers']);
});

it('refuses to delete a plan that customers use', function () {
    $plan = Plan::factory()->create();
    Account::factory()->count(3)->create(['plan_id' => $plan->id]);

    Livewire::test('pages::plans.index')
        ->call('delete', $plan->id)
        ->assertDispatched('toast', variant: 'danger', message: '3 customers use this plan. Move them to another plan first.');

    expect($plan->fresh()->trashed())->toBeFalse();
});

it('deletes an unused plan and keeps its FreeRADIUS rows', function () {
    $plan = Plan::factory()->create(['slug' => 'old']);
    DB::table('radgroupreply')->insert(['groupname' => 'old', 'attribute' => 'Mikrotik-Rate-Limit', 'op' => '=', 'value' => '5M/5M']);

    Livewire::test('pages::plans.index')->call('delete', $plan->id)->assertDispatched('toast', variant: 'success');

    expect($plan->fresh()->trashed())->toBeTrue()
        ->and(DB::table('radgroupreply')->where('groupname', 'old')->count())->toBe(1);
});

it('restores a deleted plan and rewrites its FreeRADIUS rows', function () {
    $plan = Plan::factory()->trashed()->create(['slug' => 'old', 'upload' => '5M', 'download' => '5M']);

    Livewire::test('pages::plans.index')->call('restore', $plan->id)->assertDispatched('toast', variant: 'success');

    expect($plan->fresh()->trashed())->toBeFalse()
        ->and(DB::table('radgroupreply')->where('groupname', 'old')->value('value'))->toBe('5M/5M');
});

it('shows the validity in its unit and from the first login', function () {
    Plan::factory()->hours(3)->firstLogin()->create(['name' => 'Hotspot 3h']);

    Livewire::test('pages::plans.index')->assertSee('3 hours from first login');
});
