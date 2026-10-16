<?php

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
