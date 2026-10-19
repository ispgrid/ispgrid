<?php

use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use App\Support\Addons\CustomerCards;
use Livewire\Livewire;
use Tests\Fixtures\FixtureCustomerCard;

beforeEach(function () {
    Livewire::component('fixture-card', FixtureCustomerCard::class);
    $this->actingAs(User::factory()->create());
});

it('shows an add-on card on the customer page, after the service and profile', function () {
    app(CustomerCards::class)->register('fixture-card', fn (User $user, Account $account): bool => true);
    $account = Account::factory()->create(['username' => 'alice']);

    $this->get(route('customers.show', $account))
        ->assertOk()
        ->assertSeeInOrder([__('Service'), __('Profile'), 'Fixture card for alice']);
});

it('leaves a card out for staff it does not allow', function () {
    app(CustomerCards::class)->register('fixture-card', fn (User $user, Account $account): bool => $user->isAdmin());
    $account = Account::factory()->create(['username' => 'alice']);
    $this->actingAs(User::factory()->role(Role::TECHNICIAN)->create());

    $this->get(route('customers.show', $account))
        ->assertOk()
        ->assertDontSee('Fixture card for alice');
});

it('shows the cards in their order', function () {
    Livewire::component('second-card', FixtureCustomerCard::class);
    app(CustomerCards::class)->register('second-card', fn (): bool => true, 20);
    app(CustomerCards::class)->register('fixture-card', fn (): bool => true, 10);

    expect(app(CustomerCards::class)->for(User::query()->first(), Account::factory()->create()))
        ->toBe(['fixture-card', 'second-card']);
});
