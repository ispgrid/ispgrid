<?php

use App\Models\Item;
use App\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;

it('keeps names unique among active items only', function () {
    Item::factory()->create(['name' => 'Router']);
    Item::factory()->inactive()->create(['name' => 'Router']);

    expect(Item::query()->where('name', 'Router')->count())->toBe(2);

    Item::factory()->create(['name' => 'Router']);
})->throws(UniqueConstraintViolationException::class);

it('grants manage items to roles that manage billing', function () {
    $accounts = Role::create(['name' => 'Accounts']);
    $accounts->givePermissionTo(['manage-billing']);
    $desk = Role::create(['name' => 'Front desk']);
    $desk->givePermissionTo(['view-customers', 'recharge-customers']);

    (require database_path('migrations/2026_11_14_000000_create_items_table.php'))->grant();

    expect($accounts->fresh()->permissions->pluck('name')->all())->toContain('manage-items')
        ->and($desk->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['recharge-customers', 'view-customers']);
});

it('casts the item fields', function () {
    $item = Item::factory()->staticIp()->create(['price' => '300'])->refresh();

    expect($item->sold_as->value)->toBe('service')
        ->and($item->service_kind->value)->toBe('static_ip')
        ->and($item->type->value)->toBe('service')
        ->and($item->taxable)->toBeTrue()
        ->and($item->active)->toBeTrue();
});
