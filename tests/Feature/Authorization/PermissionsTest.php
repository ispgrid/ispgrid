<?php

use App\Models\ConnectionType;
use App\Models\User;

it('lets staff disable other staff but not themselves', function () {
    $staff = User::factory()->create();
    $other = User::factory()->create();

    expect($staff->can('delete', $other))->toBeTrue()
        ->and($staff->can('delete', $staff))->toBeFalse();
});

it('gives every staff member the system screens', function (string $ability) {
    expect(User::factory()->create()->can($ability))->toBeTrue();
})->with(['manage-app-settings', 'view-activity-log']);

it('lets every staff member manage connection types', function (string $ability) {
    expect(User::factory()->create()->can($ability, ConnectionType::factory()->create()))->toBeTrue();
})->with(['update', 'delete', 'restore']);
