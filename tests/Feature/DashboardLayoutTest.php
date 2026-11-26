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

it('keeps money amounts a size smaller than the counts, so large amounts fit', function () {
    turnInvoicingOn();
    $this->actingAs(staffHolding(...Permission::cases()));

    Livewire::test('pages::dashboard')
        ->assertSeeHtml('text-xl font-semibold tabular-nums sm:text-2xl')
        ->assertSeeHtml('text-2xl font-semibold tabular-nums sm:text-3xl');
});

it('keeps the Dues amount compact in the attention row', function () {
    turnInvoicingOn();
    $this->actingAs(staffHolding(...Permission::cases()));

    expect(Livewire::test('pages::dashboard')->instance()->cardGroups['attention'])
        ->each(fn ($card) => $card->compact->toBe($card->value['label'] === 'Dues'));
});

it('orders Needs attention from the most urgent', function () {
    turnInvoicingOn();
    $this->actingAs(staffHolding(...Permission::cases()));

    expect(array_column(Livewire::test('pages::dashboard')->instance()->cardGroups['attention'], 'label'))
        ->toBe(['Expired in the last 7 days', 'Expiring within 7 days', 'Disabled', 'Dues']);
});

it('colors the Needs attention heading only when a tile needs attention', function () {
    $this->actingAs(staffHolding(...Permission::cases()));

    Livewire::test('pages::dashboard')->assertSee('Needs attention')->assertDontSeeHtml('text-red-700!');

    Account::factory()->suspended()->create();

    Livewire::test('pages::dashboard')->assertSeeHtml('text-red-700!');
});
