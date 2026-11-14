<?php

use App\Actions\Billing\IssueInvoice;
use App\Billing\LineInput;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Item;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-14 10:00:00'));
    turnInvoicingOn(tax: ['rate' => '18', 'state' => '09', 'item_code' => '998422']);
    $this->account = Account::factory()->create();
    $this->actingAs(staffHolding(...Permission::cases()));
});

it('fills a line from the picked item', function () {
    $item = Item::factory()->create(['name' => 'Tenda N300 Router', 'price' => 999, 'taxable' => false,
        'description' => 'Dual band', 'item_code' => '85176930']);

    Livewire::test('pages::customers.billing-tab', ['accountId' => $this->account->id])
        ->call('openNewInvoice')
        ->assertSee('Tenda N300 Router')
        ->call('pickItem', 0, (string) $item->id)
        ->assertSet('lines.0.description', 'Tenda N300 Router')
        ->assertSet('lines.0.details', 'Dual band')
        ->assertSet('lines.0.unit_price', '999')
        ->assertSet('lines.0.taxed', false)
        ->assertSet('lines.0.item_code', '85176930')
        ->assertSet('lines.0.item_id', $item->id)
        ->set('lines.0.unit_price', '949')
        ->set('paid_now', false)
        ->call('createInvoice')
        ->assertHasNoErrors();

    $line = Invoice::query()->sole()->lines()->sole();
    expect($line->item_id)->toBe($item->id)
        ->and($line->unit_price)->toBe('949.00');
});

it('keeps the default item code when the item has none', function () {
    $item = Item::factory()->create(['item_code' => null]);

    Livewire::test('pages::customers.billing-tab', ['accountId' => $this->account->id])
        ->call('openNewInvoice')
        ->call('pickItem', 0, (string) $item->id)
        ->assertSet('lines.0.item_code', '998422');
});

it('lists only active one-time items in the picker', function () {
    Item::factory()->create(['name' => 'Fibre patch cord']);
    Item::factory()->inactive()->create(['name' => 'Old ONT']);
    Item::factory()->staticIp()->create(['name' => 'Static IP']);

    Livewire::test('pages::customers.billing-tab', ['accountId' => $this->account->id])
        ->call('openNewInvoice')
        ->assertSee('Fibre patch cord')
        ->assertDontSee('Old ONT')
        ->assertDontSee('Static IP');
});

it('refuses a service item or an inactive item on a new line', function (string $state) {
    $item = Item::factory()->{$state}()->create();

    Livewire::test('pages::customers.billing-tab', ['accountId' => $this->account->id])
        ->call('openNewInvoice')
        ->set('lines.0.description', 'Something')
        ->set('lines.0.unit_price', '100')
        ->set('lines.0.item_id', $item->id)
        ->set('paid_now', false)
        ->call('createInvoice')
        ->assertHasErrors(['lines.0.item_id']);

    expect(Invoice::query()->count())->toBe(0);
})->with(['staticIp', 'inactive']);

it('keeps item ids on cancel and reissue', function () {
    $item = Item::factory()->create(['name' => 'Router']);
    $old = app(IssueInvoice::class)->handle($this->account, [new LineInput('Router', null, null, '1', '1000', '0', true, itemId: $item->id)],
        false, InvoiceKind::Manual, now());
    $item->update(['active' => false]);

    Livewire::test('pages::customers.billing-tab', ['accountId' => $this->account->id])
        ->call('openReissueInvoice', $old->id)
        ->set('lines.0.item_id', null)
        ->set('lines.0.unit_price', '900')
        ->set('reissue_reason', 'Wrong price')
        ->call('reissueInvoice')
        ->assertHasNoErrors();

    expect(Invoice::query()->where('replaces_invoice_id', $old->id)->sole()->lines()->sole()->item_id)->toBe($item->id);
});

it('only deactivates an item used on an invoice', function () {
    $item = Item::factory()->create();
    app(IssueInvoice::class)->handle($this->account, [new LineInput('Router', null, null, '1', '1000', '0', true, itemId: $item->id)],
        false, InvoiceKind::Manual, now());

    Livewire::test('pages::items.index')
        ->call('delete', $item->id)
        ->assertDispatched('toast', message: 'This item is in use. Make it inactive instead.', variant: 'danger');

    expect(Item::query()->find($item->id))->not->toBeNull()
        ->and($item->isUsed())->toBeTrue();
});
