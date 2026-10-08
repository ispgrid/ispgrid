<?php

use App\Models\User;

it('sends visitors from the home page to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

it('shows the signed-in staff member and a way to log out', function () {
    $this->actingAs(User::factory()->create(['name' => 'Asha Rao', 'email' => 'asha@example.com']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Asha Rao')
        ->assertSee('asha@example.com')
        ->assertSee('data-test="logout-button"', false);
});
