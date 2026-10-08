<?php

use App\Enums\SlotMode;
use App\Models\AddressList;
use App\Models\Plan;
use Illuminate\Database\UniqueConstraintViolationException;

it('adds each address list to a plan only once', function () {
    $plan = Plan::factory()->create();
    $list = AddressList::factory()->create();

    $plan->addressLists()->attach($list);
    $plan->addressLists()->attach($list);
})->throws(UniqueConstraintViolationException::class);

it('reads its time slot mode as an enum', function () {
    $plan = Plan::factory()->boostSlot()->create();

    expect($plan->fresh()->slot_mode)->toBe(SlotMode::Boost);
});
