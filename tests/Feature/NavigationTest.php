<?php

use App\Models\Role;
use App\Models\User;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;

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

it('shows Import in the System menu and opens it there', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('import.index'))
        ->assertSee(route('import.index'))
        ->assertSee("navGroup('system', true)", false);
});

it('shows an added menu item in its place for staff who may see it', function () {
    app(Navigation::class)->add(new NavItem(
        group: 'main',
        label: fn (): string => 'Session records',
        route: 'dashboard',
        icon: 'document-magnifying-glass',
        active: ['nothing.*'],
        visible: fn (User $user): bool => $user->isAdmin(),
        order: 45,
    ));

    $this->actingAs(User::factory()->create())
        ->get(route('online.index'))
        ->assertSeeInOrder([__('Login log'), 'Session records', __('Plans')]);

    $this->actingAs(User::factory()->role(Role::TECHNICIAN)->create())
        ->get(route('online.index'))
        ->assertDontSee('Session records');
});

it('hides a menu group with nothing visible in it', function () {
    $this->actingAs(User::factory()->role(Role::TECHNICIAN)->create())
        ->get(route('dashboard'))
        ->assertDontSee("navGroup('system'", false);
});

it('hides the Add-ons group while no add-on adds to it', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertDontSee("navGroup('addons'", false);
});

it('shows an add-on item in the Add-ons group, between Notifications and System', function () {
    app(Navigation::class)->add(new NavItem(
        group: 'addons',
        label: fn (): string => 'Fixture add-on',
        route: 'dashboard',
        icon: 'puzzle-piece',
        active: ['nothing.*'],
        visible: fn (User $user): bool => true,
        order: 10,
    ));
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertSee("navGroup('addons', false)", false)
        ->assertSee('Fixture add-on');

    expect(array_column(app(Navigation::class)->forUser($admin, request()), 'name'))
        ->toBe(['main', 'network', 'notifications', 'addons', 'system']);
});
