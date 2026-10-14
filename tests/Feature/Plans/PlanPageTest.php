<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\AddressList;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->actingAs(User::factory()->create());
});

function showPlan(Plan $plan): Testable
{
    return Livewire::test('pages::plans.show', ['plan' => $plan->id]);
}

it('opens a plan from the plans list', function () {
    $plan = Plan::factory()->create(['name' => 'Home 25']);

    Livewire::test('pages::plans.index')->assertSee(route('plans.show', $plan));
    $this->get(route('plans.show', $plan))->assertOk()->assertSee('Home 25');
});

it('shows what the plan gives, leaving out what it does not set', function () {
    $plan = Plan::factory()->create([
        'name' => 'Home 25', 'slug' => 'home-25', 'upload' => '10M', 'download' => '25M', 'validity' => 30, 'price' => 1499,
        'burst_upload' => null, 'monthly_data_gb' => 500, 'pool_id' => Pool::factory()->create(['name' => 'pppoe-main'])->id,
        'description' => 'Our best seller',
    ]);
    $plan->addressLists()->attach(AddressList::factory()->create(['name' => 'allowed']));

    showPlan($plan)
        ->assertSeeInOrder(['Home 25', 'home-25', '10M/25M', '30 days', '₹1,499.00', 'pppoe-main', 'allowed', '500', 'Our best seller'])
        ->assertDontSee(__('Burst upload'));
});

it('lists the customers on the plan, paginated, deleted ones left out', function () {
    $plan = Plan::factory()->create();
    foreach (range(1, 30) as $number) {
        Account::factory()->create(['plan_id' => $plan->id, 'username' => sprintf('user%02d', $number)]);
    }
    Account::factory()->trashed()->create(['plan_id' => $plan->id, 'username' => 'gone']);
    Account::factory()->create(['username' => 'elsewhere']);

    showPlan($plan)
        ->assertSee(trans_choice(':count customer|:count customers', 30))
        ->assertSeeInOrder(['user01', 'user25'])
        ->assertDontSee('user26')
        ->assertDontSee('gone')
        ->assertDontSee('elsewhere')
        ->assertSee(route('customers.show', Account::query()->where('username', 'user01')->sole()))
        ->call('gotoPage', 2)
        ->assertSee('user26')
        ->assertSee('user30')
        ->assertDontSee('user01');
});

it('says when nobody is on the plan', function () {
    showPlan(Plan::factory()->create())->assertSee(__('No customer is on this plan.'));
});

it('shows a deleted plan as deleted', function () {
    $plan = Plan::factory()->create();
    $plan->delete();

    showPlan($plan)->assertOk()->assertSee(__('Deleted'));
});

it('offers Edit only to roles that may change plans', function () {
    $plan = Plan::factory()->create();

    showPlan($plan)->assertSee(route('plans.edit', $plan));

    $this->actingAs(staffHolding(Permission::ViewPlans));
    showPlan($plan)->assertOk()->assertDontSee(route('plans.edit', $plan));
});

it('shows only the customer count to roles that cannot see customers', function () {
    $plan = Plan::factory()->create();
    Account::factory()->create(['plan_id' => $plan->id, 'username' => 'alice']);

    $this->actingAs(staffHolding(Permission::ViewPlans));

    showPlan($plan)
        ->assertSee(trans_choice(':count customer|:count customers', 1))
        ->assertDontSee('alice');
});

it('refuses roles without the plans box', function () {
    $this->actingAs(staffLacking(Permission::ViewPlans))
        ->get(route('plans.show', Plan::factory()->create()))
        ->assertForbidden();
});
