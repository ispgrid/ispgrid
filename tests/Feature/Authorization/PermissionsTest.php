<?php

use App\Models\Account;
use App\Models\AddressList;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;

it('lets staff disable other staff but not themselves', function () {
    $staff = User::factory()->create();
    $other = User::factory()->create();

    expect($staff->can('delete', $other))->toBeTrue()
        ->and($staff->can('delete', $staff))->toBeFalse();
});

it('gives every staff member the system screens', function (string $ability) {
    expect(User::factory()->create()->can($ability))->toBeTrue();
})->with(['manage-app-settings', 'view-activity-log', 'manage-radius-settings', 'view-online-sessions', 'disconnect-sessions']);

it('lets every staff member manage connection types', function (string $ability) {
    expect(User::factory()->create()->can($ability, ConnectionType::factory()->create()))->toBeTrue();
})->with(['update', 'delete', 'restore']);

it('lets every staff member manage the network records', function (string $model, string $ability) {
    expect(User::factory()->create()->can($ability, $model::factory()->create()))->toBeTrue();
})->with([Nas::class, Pool::class, IpRange::class, AddressList::class])->with(['update', 'delete', 'restore']);

it('lets every staff member take an IP out of the inventory and put it back', function (string $ability) {
    expect(User::factory()->create()->can($ability, Ip::factory()->create()))->toBeTrue();
})->with(['delete', 'restore']);

it('lets every staff member manage plans', function (string $ability) {
    expect(User::factory()->create()->can($ability, Plan::factory()->create()))->toBeTrue();
})->with(['update', 'delete', 'restore']);

it('lets every staff member manage customers', function (string $ability) {
    expect(User::factory()->create()->can($ability, Account::factory()->create()))->toBeTrue();
})->with(['view', 'update', 'delete', 'restore']);
