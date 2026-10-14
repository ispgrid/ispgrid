<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('lists the roles with how many staff have each', function () {
    User::factory()->count(2)->role(Role::TECHNICIAN)->create();
    User::factory()->role(Role::TECHNICIAN)->trashed()->create();

    $this->get(route('roles.index'))
        ->assertOk()
        ->assertSeeInOrder([__('Admin'), __('All permissions, cannot be changed'), __('Technician'), trans_choice(':count staff member|:count staff members', 3)]);
});

it('offers no edit or delete for Admin', function () {
    $admin = Role::findByName(Role::ADMIN);

    Livewire::test('pages::staff.roles')
        ->assertDontSee(route('roles.edit', $admin))
        ->assertDontSeeHtml("delete({$admin->id})")
        ->call('delete', $admin->id)
        ->assertForbidden();

    $this->get(route('roles.edit', $admin))->assertForbidden();
});

it('adds a role with the ticked boxes and the view boxes they need', function () {
    Livewire::test('pages::staff.role-form')
        ->set('name', 'Billing desk')
        ->set('permissions', ['recharge-customers', 'manage-plans'])
        ->assertSet('permissions', ['recharge-customers', 'manage-plans', 'view-customers', 'view-plans'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('roles.index'));

    expect(Role::findByName('Billing desk')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['manage-plans', 'recharge-customers', 'view-customers', 'view-plans'])
        ->and(Activity::query()->where('event', 'created')->where('subject_type', (new Role)->getMorphClass())->sole()->attribute_changes['attributes']['name'])
        ->toBe('Billing desk');
});

it('edits the Technician boxes and logs what changed', function () {
    $technician = Role::findByName(Role::TECHNICIAN);

    Livewire::test('pages::staff.role-form', ['role' => $technician->id])
        ->assertSet('name', 'Technician')
        ->set('permissions', ['view-customers'])
        ->call('save')
        ->assertHasNoErrors();

    $log = Activity::query()->where('event', 'updated')->where('subject_type', (new Role)->getMorphClass())->sole();

    expect($technician->fresh()->permissions->pluck('name')->all())->toBe(['view-customers'])
        ->and($log->attribute_changes['attributes']['permissions'])->toBe(Permission::ViewCustomers->label())
        ->and($log->attribute_changes['old'])->not->toHaveKey('name');
});

it('refuses a role name that is taken, empty or a box that does not exist', function (string $field, mixed $value) {
    Livewire::test('pages::staff.role-form')
        ->set('name', 'Billing desk')
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field]);

    expect(Role::query()->where('name', 'Billing desk')->exists())->toBeFalse();
})->with([
    'taken' => ['name', 'technician'],
    'Admin' => ['name', 'Admin'],
    'empty' => ['name', ''],
    'unknown box' => ['permissions', ['manage-staff']],
]);

it('saves a role name in any language', function () {
    Livewire::test('pages::staff.role-form')->set('name', 'बिलिंग')->set('permissions', ['view-customers'])->call('save')->assertHasNoErrors();

    expect(Role::query()->where('name', 'बिलिंग')->exists())->toBeTrue();
});

it('deletes a role nobody has', function () {
    $role = Role::create(['name' => 'Billing desk']);

    Livewire::test('pages::staff.roles')->call('delete', $role->id)->assertDispatched('toast', variant: 'success');

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'deleted')->sole()->attribute_changes['old']['name'])->toBe('Billing desk');
});

it('refuses to delete a role someone has, disabled staff included', function () {
    User::factory()->role(Role::TECHNICIAN)->trashed()->create();

    Livewire::test('pages::staff.roles')
        ->call('delete', Role::findByName(Role::TECHNICIAN)->id)
        ->assertDispatched('toast', variant: 'danger');

    expect(Role::query()->where('name', Role::TECHNICIAN)->exists())->toBeTrue();
});

it('keeps the Roles screens from staff who are not Admin', function () {
    $this->actingAs(User::factory()->role(Role::TECHNICIAN)->create());

    Livewire::test('pages::staff.roles')->assertForbidden();
    Livewire::test('pages::staff.role-form')->assertForbidden();
});
