<?php

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
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
        'username' => 'alice', 'fullname' => 'Alice Rao', 'type' => 'PPPoE', 'plan' => 'Home 25', 'status' => 'Active',
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
        ->set('expiring', true)
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
        ->set('expired', true)
        ->assertSee('lapsed')
        ->assertDontSee('paidup');
});
