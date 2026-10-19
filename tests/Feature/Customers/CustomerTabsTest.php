<?php

use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use App\Support\Addons\CustomerTabs;
use Livewire\Livewire;
use Tests\Fixtures\FixtureCustomerCard;

beforeEach(function () {
    Livewire::component('fixture-tab', FixtureCustomerCard::class);
    $this->actingAs(User::factory()->create());
});

it('shows the tabs in order and renders only the open one, Details first', function () {
    $account = Account::factory()->create();

    $this->get(route('customers.show', $account))
        ->assertOk()
        ->assertSeeInOrder([__('Details'), __('Connection'), __('Recharges'), __('Messages'), __('Activity')])
        ->assertSee(__('Profile'))
        ->assertDontSee(__('Login attempts'));
});

it('opens the tab named in the address, and Details for one that does not exist', function () {
    $account = Account::factory()->create();

    Livewire::withQueryParams(['tab' => 'connection'])->test('pages::customers.show', ['account' => $account->id])
        ->assertSee(__('Login attempts'))
        ->assertDontSee(__('Profile'));

    Livewire::withQueryParams(['tab' => 'nope'])->test('pages::customers.show', ['account' => $account->id])
        ->assertSee(__('Profile'));
});

it('switches to another tab', function () {
    Livewire::test('pages::customers.show', ['account' => Account::factory()->create()->id])
        ->set('tab', 'activity')
        ->assertSee(__('Changes'))
        ->assertDontSee(__('Profile'));
});

it('shows an add-on tab in its order and renders its component only while it is open', function () {
    app(CustomerTabs::class)->register('fixture', fn (): string => 'Fixture tab', 'fixture-tab', fn (User $user, Account $account): bool => true);
    $account = Account::factory()->create(['username' => 'alice']);

    $this->get(route('customers.show', $account))
        ->assertSeeInOrder([__('Connection'), 'Fixture tab', __('Recharges')])
        ->assertDontSee('Fixture card for alice');

    Livewire::withQueryParams(['tab' => 'fixture'])->test('pages::customers.show', ['account' => $account->id])
        ->assertSee('Fixture card for alice')
        ->assertDontSee(__('Profile'));
});

it('hides an add-on tab from staff it does not allow, and opens Details instead', function () {
    app(CustomerTabs::class)->register('fixture', fn (): string => 'Fixture tab', 'fixture-tab', fn (User $user, Account $account): bool => $user->isAdmin());
    $account = Account::factory()->create(['username' => 'alice']);
    $this->actingAs(User::factory()->role(Role::TECHNICIAN)->create());

    Livewire::withQueryParams(['tab' => 'fixture'])->test('pages::customers.show', ['account' => $account->id])
        ->assertDontSee('Fixture tab')
        ->assertDontSee('Fixture card for alice')
        ->assertSee(__('Profile'));
});

it('refuses an add-on tab key that clashes with core or cannot go in the address', function (string $key) {
    app(CustomerTabs::class)->register($key, fn (): string => 'Tab', 'fixture-tab', fn (): bool => true);
})->throws(InvalidArgumentException::class)->with(['details', 'activity', 'Documents', '', 'a/b', 'kyc docs']);
