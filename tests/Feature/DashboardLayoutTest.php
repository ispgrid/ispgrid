<?php

use App\Enums\Permission;
use App\Models\Account;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-10 12:00:00')));

it('shows the attention row and the card groups', function () {
    turnInvoicingOn();
    $this->actingAs(staffHolding(...Permission::cases()));
    Account::factory()->suspended()->create();

    Livewire::test('pages::dashboard')
        ->assertSeeInOrder(['Needs attention', 'Disabled', 'Dues', 'Customers', 'Online now', 'Renewals', 'Renewed today', 'Money', 'Collected today']);
});

it('hides Money and the Dues tile without View sales', function () {
    turnInvoicingOn();
    $this->actingAs(staffHolding(Permission::ViewCustomers));

    Livewire::test('pages::dashboard')
        ->assertSee('Needs attention')->assertDontSee('Dues')->assertDontSee('Collected today');
});

it('renders on an empty database', function () {
    $this->actingAs(staffHolding(...Permission::cases()));

    Livewire::test('pages::dashboard')->assertOk()->assertSee('Needs attention')->assertSeeHtml('<polyline points="0,12 20,12');
});
