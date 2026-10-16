<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Takes a permission away the way an Admin does on the Roles screen. The next request loads the staff member fresh.
 */
function revokeFromRole(Role $role, User $staff, Permission $permission): void
{
    $role->revokePermissionTo($permission->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $staff->unsetRelation('roles')->unsetRelation('permissions');
}

it('stops an open list page once its permission is taken away', function () {
    $role = Role::findByName(Role::TECHNICIAN);
    $staff = User::factory()->role(Role::TECHNICIAN)->create();
    $this->actingAs($staff);
    $page = Livewire::test('pages::customers.index')->assertOk();

    revokeFromRole($role, $staff, Permission::ViewCustomers);

    $page->set('search', 'a')->assertForbidden();
});

it('stops an open customer page once its permission is taken away', function () {
    $role = Role::findByName(Role::TECHNICIAN);
    $staff = User::factory()->role(Role::TECHNICIAN)->create();
    $account = Account::factory()->create();
    $this->actingAs($staff);
    $page = Livewire::test('pages::customers.show', ['account' => $account->id])->assertOk();

    revokeFromRole($role, $staff, Permission::ViewCustomers);

    $page->call('$refresh')->assertForbidden();
});

it('makes every page behind a permission check it on every request', function () {
    // Pages every logged-in staff member may use.
    $open = ['dashboard', 'settings'];

    $pages = collect(File::allFiles(resource_path('views/pages')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php' && ! str_ends_with($file->getFilename(), '.blade.php'))
        ->reject(fn ($file): bool => in_array(explode(DIRECTORY_SEPARATOR, $file->getRelativePath())[0], $open, true))
        ->reject(fn ($file): bool => str_contains(File::get($file->getPathname()), 'use AuthorizesEveryRequest'))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($pages)->toBe([]);
});
