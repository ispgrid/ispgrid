<?php

use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Asha']);
    $this->actingAs($this->admin);
});

function technicianRole(): Role
{
    return Role::findByName(Role::TECHNICIAN);
}

it('shows each staff member with their role', function () {
    User::factory()->role(Role::TECHNICIAN)->create(['name' => 'Ravi']);

    Livewire::test('pages::staff.index')
        ->assertSeeInOrder(['Asha', __('Admin'), 'Ravi', __('Technician')]);
});

it('adds a staff member with the chosen role', function () {
    Livewire::test('pages::staff.index')
        ->set('name', 'Meera')
        ->set('email', 'meera@example.com')
        ->set('password', 'secret-pass-123')
        ->set('password_confirmation', 'secret-pass-123')
        ->set('role', (string) technicianRole()->id)
        ->call('add')
        ->assertHasNoErrors();

    expect(User::query()->where('email', 'meera@example.com')->sole()->getRoleNames()->all())->toBe([Role::TECHNICIAN]);
});

it('asks for a role before adding anyone', function (string $role) {
    Livewire::test('pages::staff.index')
        ->set('name', 'Meera')
        ->set('email', 'meera@example.com')
        ->set('password', 'secret-pass-123')
        ->set('password_confirmation', 'secret-pass-123')
        ->set('role', $role)
        ->call('add')
        ->assertHasErrors(['role']);

    expect(User::query()->where('email', 'meera@example.com')->exists())->toBeFalse();
})->with(['none' => '', 'unknown' => '999999']);

it('changes the role of another staff member and logs it', function () {
    $ravi = User::factory()->create(['name' => 'Ravi']);

    Livewire::test('pages::staff.index')
        ->call('editRole', $ravi->id)
        ->assertSet('role', (string) Role::findByName(Role::ADMIN)->id)
        ->set('role', (string) technicianRole()->id)
        ->call('saveRole')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $log = Activity::query()->where('event', 'role-changed')->sole();

    expect($ravi->fresh()->getRoleNames()->all())->toBe([Role::TECHNICIAN])
        ->and($log->subject->is($ravi))->toBeTrue()
        ->and($log->attribute_changes->toArray())->toEqual(['attributes' => ['role' => 'Technician'], 'old' => ['role' => 'Admin']]);
});

it('does not let an Admin change their own role', function () {
    Livewire::test('pages::staff.index')->call('editRole', $this->admin->id)->assertForbidden();

    expect($this->admin->fresh()->isAdmin())->toBeTrue();
});

it('offers no role change on your own row', function () {
    $ravi = User::factory()->create();

    Livewire::test('pages::staff.index')
        ->assertSeeHtml("editRole({$ravi->id})")
        ->assertDontSeeHtml("editRole({$this->admin->id})");
});

it('keeps the Staff screen from staff who are not Admin', function () {
    $this->actingAs(User::factory()->role(Role::TECHNICIAN)->create());

    Livewire::test('pages::staff.index')->assertForbidden();
});
