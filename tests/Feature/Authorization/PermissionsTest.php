<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\AddressList;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;

it('allows a policy ability only with its box', function (string $model, string $ability, Permission $permission, bool $onModel) {
    $subject = $onModel ? $model::factory()->create() : $model;

    expect(staffHolding($permission)->can($ability, $subject))->toBeTrue()
        ->and(staffLacking($permission)->can($ability, $subject))->toBeFalse()
        ->and(User::factory()->create()->can($ability, $subject))->toBeTrue();
})->with([
    'view customers' => [Account::class, 'viewAny', Permission::ViewCustomers, false],
    'open a customer' => [Account::class, 'view', Permission::ViewCustomers, true],
    'add a customer' => [Account::class, 'create', Permission::AddCustomers, false],
    'edit a customer' => [Account::class, 'update', Permission::EditCustomers, true],
    'recharge' => [Account::class, 'recharge', Permission::RechargeCustomers, true],
    'change status' => [Account::class, 'changeStatus', Permission::ChangeCustomerStatus, true],
    'delete a customer' => [Account::class, 'delete', Permission::DeleteCustomers, true],
    'restore a customer' => [Account::class, 'restore', Permission::DeleteCustomers, true],
    'see the password' => [Account::class, 'viewPassword', Permission::ViewCustomerPasswords, true],
    'reset devices' => [Account::class, 'resetDevices', Permission::ResetCustomerDevices, true],
    'view plans' => [Plan::class, 'viewAny', Permission::ViewPlans, false],
    'add a plan' => [Plan::class, 'create', Permission::ManagePlans, false],
    'edit a plan' => [Plan::class, 'update', Permission::ManagePlans, true],
    'delete a plan' => [Plan::class, 'delete', Permission::ManagePlans, true],
    'restore a plan' => [Plan::class, 'restore', Permission::ManagePlans, true],
    'view NAS' => [Nas::class, 'viewAny', Permission::ViewNetwork, false],
    'add a NAS' => [Nas::class, 'create', Permission::ManageNas, false],
    'edit a NAS' => [Nas::class, 'update', Permission::ManageNas, true],
    'delete a NAS' => [Nas::class, 'delete', Permission::ManageNas, true],
    'restore a NAS' => [Nas::class, 'restore', Permission::ManageNas, true],
    'view pools' => [Pool::class, 'viewAny', Permission::ViewNetwork, false],
    'add a pool' => [Pool::class, 'create', Permission::ManagePools, false],
    'edit a pool' => [Pool::class, 'update', Permission::ManagePools, true],
    'delete a pool' => [Pool::class, 'delete', Permission::ManagePools, true],
    'restore a pool' => [Pool::class, 'restore', Permission::ManagePools, true],
    'view IP ranges' => [IpRange::class, 'viewAny', Permission::ViewNetwork, false],
    'open an IP range' => [IpRange::class, 'view', Permission::ViewNetwork, true],
    'add an IP range' => [IpRange::class, 'create', Permission::ManageIpRanges, false],
    'edit an IP range' => [IpRange::class, 'update', Permission::ManageIpRanges, true],
    'delete an IP range' => [IpRange::class, 'delete', Permission::ManageIpRanges, true],
    'restore an IP range' => [IpRange::class, 'restore', Permission::ManageIpRanges, true],
    'take an IP out' => [Ip::class, 'delete', Permission::ManageIpRanges, true],
    'put an IP back' => [Ip::class, 'restore', Permission::ManageIpRanges, true],
    'view address lists' => [AddressList::class, 'viewAny', Permission::ViewNetwork, false],
    'add an address list' => [AddressList::class, 'create', Permission::ManageAddressLists, false],
    'edit an address list' => [AddressList::class, 'update', Permission::ManageAddressLists, true],
    'delete an address list' => [AddressList::class, 'delete', Permission::ManageAddressLists, true],
    'restore an address list' => [AddressList::class, 'restore', Permission::ManageAddressLists, true],
    'view connection types' => [ConnectionType::class, 'viewAny', Permission::ManageConnectionTypes, false],
    'add a connection type' => [ConnectionType::class, 'create', Permission::ManageConnectionTypes, false],
    'edit a connection type' => [ConnectionType::class, 'update', Permission::ManageConnectionTypes, true],
    'delete a connection type' => [ConnectionType::class, 'delete', Permission::ManageConnectionTypes, true],
    'restore a connection type' => [ConnectionType::class, 'restore', Permission::ManageConnectionTypes, true],
]);

it('allows a screen gate only with its box', function (Permission $permission) {
    expect(staffHolding($permission)->can($permission->value))->toBeTrue()
        ->and(staffLacking($permission)->can($permission->value))->toBeFalse();
})->with([
    Permission::ManageAppSettings, Permission::ManageRadiusSettings, Permission::ViewActivityLog,
    Permission::ViewOnlineSessions, Permission::DisconnectSessions, Permission::ViewLoginLog, Permission::ImportData,
]);

it('keeps staff management with Admin, whatever boxes a role holds', function (string $ability) {
    $staff = User::factory()->create();
    $everything = staffHolding(...Permission::cases());

    expect(User::factory()->create()->can($ability, $ability === 'viewAny' || $ability === 'create' ? User::class : $staff))->toBeTrue()
        ->and($everything->can($ability, $ability === 'viewAny' || $ability === 'create' ? User::class : $staff))->toBeFalse();
})->with(['viewAny', 'create', 'delete', 'restore', 'changeRole']);

it('keeps the roles screen with Admin', function () {
    expect(User::factory()->create()->can('manage-staff'))->toBeTrue()
        ->and(staffHolding(...Permission::cases())->can('manage-staff'))->toBeFalse();
});

it('lets an Admin disable other staff but not themselves', function () {
    $admin = User::factory()->create();
    $other = User::factory()->create();

    expect($admin->can('delete', $other))->toBeTrue()
        ->and($admin->can('delete', $admin))->toBeFalse();
});

it('lets an Admin change the role of other staff but not their own', function () {
    $admin = User::factory()->create();
    $other = User::factory()->create();

    expect($admin->can('changeRole', $other))->toBeTrue()
        ->and($admin->can('changeRole', $admin))->toBeFalse();
});
