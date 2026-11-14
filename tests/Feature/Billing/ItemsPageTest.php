<?php

use App\Enums\Permission;
use App\Models\CustomerService;
use App\Models\Item;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('refuses staff without manage items', function () {
    $staff = staffLacking(Permission::ManageItems);

    $this->actingAs($staff)->get(route('items.index'))->assertForbidden();
    $this->actingAs($staff)->get(route('dashboard'))->assertDontSee(route('items.index'));
    $this->actingAs(staffHolding(Permission::ManageItems))->get(route('dashboard'))->assertSee(route('items.index'));
});

it('lists active items and finds them by name or item code', function () {
    Item::factory()->create(['name' => 'Tenda N300 Router', 'item_code' => '85176930']);
    Item::factory()->create(['name' => 'Fibre patch cord']);
    Item::factory()->inactive()->create(['name' => 'Old ONT']);

    Livewire::test('pages::items.index')
        ->assertSee('Tenda N300 Router')->assertSee('Fibre patch cord')->assertDontSee('Old ONT')
        ->set('search', '8517')->assertSee('Tenda N300 Router')->assertDontSee('Fibre patch cord')
        ->set('search', '')->set('status', 'inactive')->assertSee('Old ONT')->assertDontSee('Tenda N300 Router');
});

it('creates a goods item with an HSN code under India GST', function () {
    turnInvoicingOn();

    Livewire::test('pages::items.index')
        ->call('openNew')
        ->set('name', 'Tenda N300 Router')->set('type', 'goods')->set('item_code', '85176930')->set('price', '999')
        ->call('save')
        ->assertHasNoErrors();

    $item = Item::query()->sole();
    expect($item->name)->toBe('Tenda N300 Router')
        ->and($item->item_code)->toBe('85176930')
        ->and($item->price)->toBe('999.00')
        ->and($item->sold_as->value)->toBe('one_time')
        ->and($item->service_kind)->toBeNull();
});

it('drops the item code when the tax setup is not India GST', function () {
    turnInvoicingOn('GB', 'single', ['name' => 'VAT', 'rate' => '20']);

    Livewire::test('pages::items.index')
        ->call('openNew')
        ->assertDontSee('SAC/HSN')
        ->set('name', 'Router')->set('type', 'goods')->set('item_code', '85176930')->set('price', '999')
        ->call('save')
        ->assertHasNoErrors();

    expect(Item::query()->sole()->item_code)->toBeNull();
});

it('keeps a stored item code when the setup is no longer India GST', function () {
    turnInvoicingOn('GB', 'single', ['name' => 'VAT', 'rate' => '20']);
    $item = Item::factory()->create(['name' => 'Router', 'item_code' => '85176930']);

    Livewire::test('pages::items.index')
        ->call('openEdit', $item->id)->set('price', '1099')->call('save')->assertHasNoErrors();

    expect($item->fresh()->item_code)->toBe('85176930')
        ->and($item->fresh()->price)->toBe('1099.00');
});

it('requires type service for an item sold as a service', function () {
    Livewire::test('pages::items.index')
        ->call('openNew')
        ->set('name', 'Static IP')->set('type', 'goods')->set('price', '300')->set('sold_as', 'service')->set('service_kind', 'static_ip')
        ->call('save')->assertHasErrors(['sold_as'])
        ->set('type', 'service')->set('service_kind', '')
        ->call('save')->assertHasErrors(['service_kind'])
        ->set('service_kind', 'static_ip')
        ->call('save')->assertHasNoErrors();

    expect(Item::query()->sole()->service_kind->value)->toBe('static_ip');

    Livewire::test('pages::items.index')
        ->call('openNew')
        ->set('name', 'Installation')->set('type', 'service')->set('price', '500')->set('sold_as', 'one_time')->set('service_kind', 'static_ip')
        ->call('save')->assertHasNoErrors();

    expect(Item::query()->where('name', 'Installation')->sole()->service_kind)->toBeNull();
});

it('refuses a second active item with the same name', function () {
    Item::factory()->create(['name' => 'Router']);

    Livewire::test('pages::items.index')
        ->call('openNew')
        ->set('name', 'Router')->set('type', 'goods')->set('price', '999')
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSee('An active item already has this name.');
});

it('refuses a badly typed price', function () {
    Livewire::test('pages::items.index')
        ->call('openNew')
        ->set('name', 'Router')->set('type', 'goods')->set('price', '9.9.9')
        ->call('save')
        ->assertHasErrors(['price']);
});

it('deletes an unused item', function () {
    $item = Item::factory()->create();

    Livewire::test('pages::items.index')->call('delete', $item->id);

    expect(Item::query()->find($item->id))->toBeNull();
});

it('refuses every action once the box is taken away', function () {
    $staff = staffHolding(Permission::ManageItems);
    $item = Item::factory()->create();
    $page = Livewire::actingAs($staff)->test('pages::items.index');

    $staff->roles->first()->revokePermissionTo('manage-items');

    $page->call('delete', $item->id)->assertForbidden();
    expect(Item::query()->find($item->id))->not->toBeNull();
});

it('keeps the kind of an item that customer services use', function (string $field, string $value, string $error) {
    $item = Item::factory()->staticIp()->create();
    CustomerService::factory()->for($item)->create();

    Livewire::test('pages::items.index')
        ->call('openEdit', $item->id)
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$error]);

    expect($item->fresh()->service_kind->value)->toBe('static_ip')
        ->and($item->fresh()->sold_as->value)->toBe('service');
})->with([['service_kind', 'plain', 'service_kind'], ['sold_as', 'one_time', 'sold_as'], ['type', 'goods', 'sold_as']]);

it('still edits the price and name of an item services use', function () {
    $item = Item::factory()->staticIp()->create();
    CustomerService::factory()->for($item)->create();

    Livewire::test('pages::items.index')
        ->call('openEdit', $item->id)->set('price', '350')->set('name', 'Static IP (new)')->call('save')->assertHasNoErrors();

    expect($item->fresh()->price)->toBe('350.00');
});
