<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('creates every permission in the list', function () {
    expect(DB::table('permissions')->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map->value->sort()->values()->all());
});

it('gives Admin every permission without storing any', function () {
    $admin = User::factory()->create();

    expect($admin->isAdmin())->toBeTrue()
        ->and(Role::findByName(Role::ADMIN)->permissions)->toBeEmpty();

    foreach (Permission::cases() as $permission) {
        expect($admin->hasAccess($permission))->toBeTrue()
            ->and($admin->can($permission->value))->toBeTrue();
    }
});

it('gives Technician the agreed defaults', function () {
    $technician = User::factory()->role(Role::TECHNICIAN)->create();

    $allowed = collect(Permission::cases())->filter(fn (Permission $permission): bool => $technician->hasAccess($permission));

    expect($allowed->map->value->values()->all())->toBe([
        'view-customers', 'add-customers', 'edit-customers', 'recharge-customers', 'change-customer-status',
        'delete-customers', 'view-customer-passwords', 'reset-customer-devices',
        'view-online-sessions', 'disconnect-sessions', 'view-login-log',
        'view-plans', 'view-network', 'view-notification-log',
    ])->and($technician->isAdmin())->toBeFalse();
});

it('refuses everything to staff without a role', function () {
    $staff = User::factory()->withoutRole()->create();

    foreach (Permission::cases() as $permission) {
        expect($staff->can($permission->value))->toBeFalse();
    }
});

it('makes existing staff Admin on upgrade, disabled staff too', function () {
    $active = User::factory()->withoutRole()->create();
    $disabled = User::factory()->withoutRole()->create();
    $disabled->delete();
    $technician = User::factory()->role(Role::TECHNICIAN)->create();

    (require database_path('migrations/2026_10_14_134910_create_staff_roles.php'))->up();

    expect($active->fresh()->isAdmin())->toBeTrue()
        ->and(User::withTrashed()->find($disabled->id)->isAdmin())->toBeTrue()
        ->and($technician->fresh()->getRoleNames()->all())->toBe([Role::TECHNICIAN]);
});

it('adds the area view box when a role gets an action of that area', function () {
    expect(Permission::withImplied(['edit-customers', 'manage-nas', 'disconnect-sessions']))
        ->toEqualCanonicalizing(['edit-customers', 'view-customers', 'manage-nas', 'view-network', 'disconnect-sessions']);
});

it('translates the built-in role names only', function () {
    app()->setLocale('hi');

    expect(Role::findByName(Role::TECHNICIAN)->label())->toBe(__('Technician'))
        ->and(Role::create(['name' => 'Billing desk'])->label())->toBe('Billing desk');
});
