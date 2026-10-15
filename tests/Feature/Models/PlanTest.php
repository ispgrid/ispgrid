<?php

use App\Enums\SlotMode;
use App\Enums\ValidityUnit;
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

it('gives a plan its validity in days unless it says hours', function () {
    $plan = Plan::factory()->create(['validity' => 30])->fresh();

    expect($plan->validity_unit)->toBe(ValidityUnit::Days)
        ->and($plan->hotspot_starts_at_first_login)->toBeFalse()
        ->and($plan->validityMinutes())->toBe(43200)
        ->and(Plan::factory()->hours(3)->create()->fresh()->validityMinutes())->toBe(180);
});

it('describes a plan validity in its own unit, from the first login for a first-login plan', function (Closure $make, string $label) {
    expect($make()->validityLabel())->toBe($label);
})->with([
    'days' => [fn () => Plan::factory()->create(['validity' => 30]), '30 days'],
    'one day' => [fn () => Plan::factory()->create(['validity' => 1]), '1 day'],
    'hours' => [fn () => Plan::factory()->hours(3)->create(), '3 hours'],
    '24 hours stay hours' => [fn () => Plan::factory()->hours(24)->create(), '24 hours'],
    'first login' => [fn () => Plan::factory()->hours(3)->firstLogin()->create(), '3 hours from first login'],
]);

it('describes waiting minutes in days when they make whole days, otherwise in hours', function (int $minutes, string $label) {
    expect(ValidityUnit::describeMinutes($minutes))->toBe($label)
        ->and(ValidityUnit::firstLoginLabel($minutes))->toBe("{$label} from first login");
})->with([[180, '3 hours'], [1440, '1 day'], [2880, '2 days'], [1500, '25 hours']]);
