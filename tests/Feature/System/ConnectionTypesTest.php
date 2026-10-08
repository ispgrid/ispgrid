<?php

use App\Models\Account;
use App\Models\ConnectionType;
use App\Models\User;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('lists types with how many customers use each', function () {
    $home = ConnectionType::factory()->create(['name' => 'Home']);
    Account::factory()->count(2)->create(['connection_type_id' => $home->id]);

    Livewire::test('pages::system.connection-types')
        ->assertSeeInOrder(['Home', '2 customers']);
});

it('adds a type and renames it', function () {
    Livewire::test('pages::system.connection-types')
        ->set('name', 'Fibre')
        ->call('add')
        ->assertHasNoErrors();

    $type = ConnectionType::query()->where('name', 'Fibre')->firstOrFail();

    Livewire::test('pages::system.connection-types')
        ->call('edit', $type->id)
        ->set('editingName', 'Fibre to the home')
        ->call('rename')
        ->assertHasNoErrors();

    expect($type->fresh()->name)->toBe('Fibre to the home');
});

it('tells staff to restore a deleted type instead of reusing its name', function () {
    ConnectionType::factory()->trashed()->create(['name' => 'Wireless']);

    Livewire::test('pages::system.connection-types')
        ->set('name', 'Wireless')
        ->call('add')
        ->assertHasErrors(['name'])
        ->assertSee('A deleted connection type uses this name. Restore it instead.');
});

it('refuses a duplicate name of a live type', function () {
    ConnectionType::factory()->create(['name' => 'Business']);

    Livewire::test('pages::system.connection-types')
        ->set('name', 'Business')
        ->call('add')
        ->assertHasErrors(['name']);
});

it('will not delete a type that customers still use', function () {
    $type = ConnectionType::factory()->create();
    Account::factory()->create(['connection_type_id' => $type->id]);

    Livewire::test('pages::system.connection-types')
        ->call('delete', $type->id)
        ->assertDispatched('toast', variant: 'danger');

    expect($type->fresh()->trashed())->toBeFalse();
});

it('soft deletes an unused type and restores it', function () {
    $type = ConnectionType::factory()->create();

    Livewire::test('pages::system.connection-types')->call('delete', $type->id);
    expect($type->fresh()->trashed())->toBeTrue();

    Livewire::test('pages::system.connection-types')->call('restore', $type->id);
    expect($type->fresh()->trashed())->toBeFalse();
});

it('links the System menu to connection types', function () {
    $this->get(route('dashboard'))->assertSee(route('connection-types.index'));
});
