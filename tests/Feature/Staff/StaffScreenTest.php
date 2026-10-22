<?php

use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Admin']);
    $this->actingAs($this->admin);
});

it('lists active and disabled staff', function () {
    User::factory()->create(['name' => 'Ravi']);
    User::factory()->trashed()->create(['name' => 'Old Hand']);

    $this->get(route('staff.index'))
        ->assertOk()
        ->assertSee('Ravi')
        ->assertSee('Old Hand')
        ->assertSee('Disabled');
});

it('adds a staff member who can then log in', function () {
    Livewire::test('pages::staff.index')
        ->set('name', 'Meera')
        ->set('email', 'meera@example.com')
        ->set('password', 'secret-pass-123')
        ->set('password_confirmation', 'secret-pass-123')
        ->set('role', (string) Role::findByName(Role::TECHNICIAN)->id)
        ->call('add')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'add-staff')
        ->assertDispatched('toast');

    auth()->logout();

    $this->post(route('login.store'), ['email' => 'meera@example.com', 'password' => 'secret-pass-123'])
        ->assertRedirect(route('dashboard', absolute: false));
});

it('tells staff to restore a disabled member instead of adding them again', function () {
    User::factory()->trashed()->create(['email' => 'gone@example.com']);

    Livewire::test('pages::staff.index')
        ->set('name', 'Gone Again')
        ->set('email', 'gone@example.com')
        ->set('password', 'secret-pass-123')
        ->set('password_confirmation', 'secret-pass-123')
        ->set('role', (string) Role::findByName(Role::TECHNICIAN)->id)
        ->call('add')
        ->assertHasErrors(['email'])
        ->assertSee('A disabled staff member uses this email. Restore them instead.');

    expect(User::withTrashed()->where('email', 'gone@example.com')->count())->toBe(1);
});

it('does not let staff disable themselves', function () {
    Livewire::test('pages::staff.index')
        ->call('disable', $this->admin->id)
        ->assertForbidden();

    expect($this->admin->fresh()->trashed())->toBeFalse();
});

it('disables and restores another staff member', function () {
    $other = User::factory()->create();

    Livewire::test('pages::staff.index')->call('disable', $other->id)->assertDispatched('toast');
    expect($other->fresh()->trashed())->toBeTrue();

    Livewire::test('pages::staff.index')->call('restore', $other->id)->assertDispatched('toast');
    expect($other->fresh()->trashed())->toBeFalse();
});

it('changes nothing when the other Admin took Admin from you a moment before', function (string $action) {
    $other = User::factory()->create();
    $technician = Role::findByName(Role::TECHNICIAN);
    $page = Livewire::test('pages::staff.index')->call('editRole', $other->id)->set('role', (string) $technician->id);

    // The other Admin demoted this one while the page was open: two Admins demoting each other at once.
    User::query()->findOrFail($this->admin->id)->syncRoles([$technician]);

    $page->call($action, ...($action === 'disable' ? [$other->id] : []))->assertDispatched('toast', variant: 'danger');

    expect($other->fresh()->isAdmin())->toBeTrue()
        ->and($other->fresh()->trashed())->toBeFalse();
})->with(['saveRole', 'disable']);

it('links the System menu to the staff screen', function () {
    $this->get(route('dashboard'))->assertSee(route('staff.index'));
});
