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

it('keeps the day-to-day menu open and folds Network and System away', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee(route('plans.index'))
        ->assertSee("navGroup('network', false)", false)
        ->assertSee("navGroup('system', false)", false);
});

it('opens the menu group of the current page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('routers.index'))
        ->assertSee("navGroup('network', true)", false)
        ->assertSee("navGroup('system', false)", false);
});

it('shows Customers in the day-to-day menu', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('customers.index'))
        ->assertOk()
        ->assertSee(route('customers.index'))
        ->assertSee(route('customers.create'));
});

it('shows Online in the day-to-day menu', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee(route('online.index'));
});

it('shows the login log in the day-to-day menu', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee(route('login-log.index'));
});
