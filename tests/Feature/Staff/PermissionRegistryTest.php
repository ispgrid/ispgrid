<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionRow;

beforeEach(function () {
    app(Permissions::class)->register('search-test-records', fn (): string => 'Search test records', fn (): string => __('Sessions'), 'view-login-log');
    PermissionRow::findOrCreate('search-test-records', 'web');
});

it('shows an add-on permission under its area on the role form', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::staff.role-form')
        ->assertSeeInOrder([__('Sessions'), __('View login log'), 'Search test records', __('Plans')]);
});

it('saves an add-on permission with the view box it implies', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::staff.role-form')
        ->set('name', 'Auditor')
        ->set('permissions', ['search-test-records'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Role::findByName('Auditor')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['search-test-records', 'view-login-log']);
});

it('lets a role with the add-on permission through its gate, and others not', function () {
    $role = Role::create(['name' => 'Auditor', 'guard_name' => 'web']);
    $role->givePermissionTo('search-test-records');
    $auditor = User::factory()->role('Auditor')->create();

    expect(Gate::forUser($auditor)->allows('search-test-records'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->role(Role::TECHNICIAN)->create())->allows('search-test-records'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create())->allows('search-test-records'))->toBeTrue();
});

it('counts add-on permissions on the roles list', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::staff.roles')
        ->assertSet('permissionCount', count(app(Permissions::class)->names()));
});

it('still saves a role that holds the box of a removed add-on, and keeps that box for a reinstall', function () {
    PermissionRow::findOrCreate('removed-addon-box', 'web');
    $role = Role::create(['name' => 'Auditor', 'guard_name' => 'web']);
    $role->givePermissionTo(['removed-addon-box', 'view-login-log']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::staff.role-form', ['role' => $role->id])
        ->assertSet('permissions', ['view-login-log'])
        ->set('permissions', ['view-login-log', 'view-plans'])
        ->call('save')
        ->assertHasNoErrors();

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['removed-addon-box', 'view-login-log', 'view-plans']);
});

it('follows implied boxes to the end of the chain', function () {
    $permissions = app(Permissions::class);
    $permissions->register('erase-x', fn (): string => 'Erase x', fn (): string => __('Customers'), 'restore-x');
    $permissions->register('restore-x', fn (): string => 'Restore x', fn (): string => __('Customers'), 'view-x');
    $permissions->register('view-x', fn (): string => 'View x', fn (): string => __('Customers'), 'view-customers');

    expect($permissions->withImplied(['erase-x']))->toEqualCanonicalizing(['erase-x', 'restore-x', 'view-x', 'view-customers']);
});

it('stops on an implied loop', function () {
    $permissions = app(Permissions::class);
    $permissions->register('loop-a', fn (): string => 'Loop a', fn (): string => __('Customers'), 'loop-b');
    $permissions->register('loop-b', fn (): string => 'Loop b', fn (): string => __('Customers'), 'loop-a');

    expect($permissions->withImplied(['loop-a']))->toEqualCanonicalizing(['loop-a', 'loop-b']);
});

it('ticks and saves every box down an implied chain', function () {
    $permissions = app(Permissions::class);
    $permissions->register('erase-x', fn (): string => 'Erase x', fn (): string => __('Customers'), 'restore-x');
    $permissions->register('restore-x', fn (): string => 'Restore x', fn (): string => __('Customers'), 'view-x');
    $permissions->register('view-x', fn (): string => 'View x', fn (): string => __('Customers'), 'view-customers');
    foreach (['erase-x', 'restore-x', 'view-x'] as $name) {
        PermissionRow::findOrCreate($name, 'web');
    }
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::staff.role-form')
        ->set('name', 'Records desk')
        ->set('permissions', ['erase-x'])
        ->assertSet('permissions', fn (array $ticked): bool => in_array('view-customers', $ticked, true))
        ->call('save')
        ->assertHasNoErrors();

    expect(Role::findByName('Records desk')->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['erase-x', 'restore-x', 'view-x', 'view-customers']);
});

it('lists the billing boxes under Billing with the view boxes they need', function () {
    $billing = array_map(fn (Permission $permission): string => $permission->value, Permission::byArea()[__('Billing')]);

    expect($billing)->toBe(['manage-billing', 'set-customer-pricing', 'view-invoices', 'create-invoices', 'record-payments', 'cancel-invoices', 'view-sales'])
        ->and(app(Permissions::class)->withImplied(['cancel-invoices']))->toEqualCanonicalizing(['cancel-invoices', 'view-invoices', 'view-customers']);
});
