<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\IpRange;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusSettingSeeder;

/*
 * Every page opens only with its box: a role without it gets 403 by URL and no menu link.
 */

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RadiusSettingSeeder::class]);
});

dataset('pages', [
    'customers' => [fn () => route('customers.index'), Permission::ViewCustomers],
    'a customer' => [fn () => route('customers.show', Account::factory()->create()), Permission::ViewCustomers],
    'add customer' => [fn () => route('customers.create'), Permission::AddCustomers],
    'edit customer' => [fn () => route('customers.edit', Account::factory()->create()), Permission::EditCustomers],
    'online' => [fn () => route('online.index'), Permission::ViewOnlineSessions],
    'login log' => [fn () => route('login-log.index'), Permission::ViewLoginLog],
    'plans' => [fn () => route('plans.index'), Permission::ViewPlans],
    'add plan' => [fn () => route('plans.create'), Permission::ManagePlans],
    'edit plan' => [fn () => route('plans.edit', Plan::factory()->create()), Permission::ManagePlans],
    'NAS' => [fn () => route('routers.index'), Permission::ViewNetwork],
    'pools' => [fn () => route('pools.index'), Permission::ViewNetwork],
    'IP ranges' => [fn () => route('ip-ranges.index'), Permission::ViewNetwork],
    'an IP range' => [fn () => route('ip-ranges.show', IpRange::factory()->create()), Permission::ViewNetwork],
    'address lists' => [fn () => route('address-lists.index'), Permission::ViewNetwork],
    'app settings' => [fn () => route('app-settings.edit'), Permission::ManageAppSettings],
    'FreeRADIUS settings' => [fn () => route('radius-settings.edit'), Permission::ManageRadiusSettings],
    'connection types' => [fn () => route('connection-types.index'), Permission::ManageConnectionTypes],
    'activity log' => [fn () => route('activity.index'), Permission::ViewActivityLog],
    'import' => [fn () => route('import.index'), Permission::ImportData],
]);

it('opens a page only with its box', function (Closure $url, Permission $permission) {
    $url = $url();

    $this->actingAs(staffLacking($permission))->get($url)->assertForbidden();
    $this->actingAs(staffHolding(...array_map(Permission::from(...), Permission::withImplied([$permission->value]))))
        ->get($url)->assertOk();
})->with('pages');

it('keeps Staff and Roles with Admin', function (string $route) {
    $this->actingAs(staffHolding(...Permission::cases()))->get(route($route))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route($route))->assertOk();
})->with(['staff.index', 'roles.index', 'roles.create']);

it('links in the menu only the pages the role can open', function () {
    $this->actingAs(User::factory()->role(Role::TECHNICIAN)->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('customers.index'))
        ->assertSee(route('online.index'))
        ->assertSee(route('login-log.index'))
        ->assertSee(route('plans.index'))
        ->assertSee(route('routers.index'))
        ->assertSee(route('address-lists.index'))
        ->assertDontSee(route('staff.index'))
        ->assertDontSee(route('roles.index'))
        ->assertDontSee(route('app-settings.edit'))
        ->assertDontSee(route('radius-settings.edit'))
        ->assertDontSee(route('connection-types.index'))
        ->assertDontSee(route('activity.index'))
        ->assertDontSee(route('import.index'))
        ->assertDontSee("navGroup('system'", false);
});

it('leaves only the dashboard to a role with no boxes', function () {
    $this->actingAs(staffHolding())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('customers.index'))
        ->assertDontSee(route('plans.index'))
        ->assertDontSee(route('routers.index'))
        ->assertDontSee(route('login-log.index'))
        ->assertDontSee("navGroup('network'", false);
});

it('shows Admin the whole menu, Roles included', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee(route('staff.index'))
        ->assertSee(route('roles.index'))
        ->assertSee(route('import.index'));
});
