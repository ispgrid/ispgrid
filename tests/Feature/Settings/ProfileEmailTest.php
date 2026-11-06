<?php

use App\Models\User;
use Livewire\Livewire;

it('needs the current password to change the email', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('email', 'new@example.com')
        ->call('updateProfileInformation')
        ->assertHasErrors('current_password');

    Livewire::test('pages::settings.profile')
        ->set('email', 'new@example.com')->set('current_password', 'wrong-password')
        ->call('updateProfileInformation')
        ->assertHasErrors('current_password');

    expect($user->fresh()->email)->toBe('old@example.com');

    Livewire::test('pages::settings.profile')
        ->set('email', 'new@example.com')->set('current_password', 'password')
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertSet('current_password', '');

    expect($user->fresh()->email)->toBe('new@example.com');
});

it('changes only the name without the password', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'New Name')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('New Name');
});
