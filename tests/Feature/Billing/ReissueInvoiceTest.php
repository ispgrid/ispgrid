<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\ReissueInvoice;
use App\Actions\Customers\RechargeAccount;
use App\Billing\LineInput;
use App\Billing\PaymentInput;
use App\Billing\Pdf\InvoiceView;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Exceptions\InvoiceAlreadyCancelled;
use App\Exceptions\InvoiceHasPayments;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    AppSetting::put('date_format', 'd/m/Y');
    turnInvoicingOn();
    $this->account = Account::factory()->create(['username' => 'alice', 'billing_name' => 'Alice Example']);
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices, Permission::CreateInvoices,
        Permission::CancelInvoices, Permission::SetCustomerPricing));
});

/**
 * A manual invoice for a router dated 15 October: one line with details, an item code and a 10% discount.
 */
function routerInvoice(Account $account, string $price = '1000'): Invoice
{
    return app(IssueInvoice::class)->handle($account, [new LineInput('Router', 'Dual band', '8517', '1', $price, '10', true)],
        false, InvoiceKind::Manual, Carbon::parse('2026-10-15'));
}

it('replaces an invoice with the next number, its date and a fresh copy of the customer', function () {
    $old = routerInvoice($this->account);
    $this->account->update(['billing_name' => 'Example Traders']);

    $new = app(ReissueInvoice::class)->handle($old->id, [new LineInput('Router', 'Dual band', '8517', '1', '900', '10', true)],
        false, Carbon::parse('2026-10-15'), 'Wrong price');

    expect($old->fresh())
        ->status->toBe(InvoiceStatus::Cancelled)
        ->cancel_reason->toBe('Wrong price')
        ->balance->toBe('0.00')
        ->and($old->fresh()->replacedBy?->id)->toBe($new->id)
        ->and($new)
        ->number->toBe('INV-2')
        ->kind->toBe(InvoiceKind::Manual)
        ->replaces_invoice_id->toBe($old->id)
        ->total->toBe('955.80')
        ->status->toBe(InvoiceStatus::Unpaid)
        ->and($new->issue_date->format('Y-m-d'))->toBe('2026-10-15')
        ->and($new->customer['name'])->toBe('Example Traders')
        ->and(Activity::query()->where('event', 'invoice-cancelled')->sole()->attribute_changes['attributes'])
        ->toMatchArray(['invoice' => 'INV-1', 'reason' => 'Wrong price', 'replaced_by' => 'INV-2'])
        ->and(Activity::query()->where('event', 'invoice-issued')->latest('id')->first()->attribute_changes['attributes'])
        ->toMatchArray(['invoice' => 'INV-2', 'replaces' => 'INV-1']);
});

it('keeps a recharge invoice tied to its recharge, and moves the recharge and an advance renewal to the new one', function () {
    $plan = Plan::factory()->create(['name' => 'Home 30', 'price' => 500, 'validity' => 30]);
    $this->account->update(['expiry' => '2026-10-20 10:00:00']);
    $recharge = app(RechargeAccount::class)->handle($this->account->id, $plan->id, Carbon::parse('2026-11-20 10:00'), '500', null);
    $old = $recharge->invoice;
    $advance = AdvanceRenewal::factory()->create(['account_id' => $this->account->id, 'plan_id' => $plan->id, 'invoice_id' => $old->id]);

    $new = app(ReissueInvoice::class)->handle($old->id, [new LineInput('Home 30', $old->lines[0]->sub_description, null, '1', '400', '0', true)],
        false, $old->issue_date, 'Agreed price');

    expect($new)
        ->kind->toBe(InvoiceKind::Recharge)
        ->recharge_id->toBe($recharge->id)
        ->period_from->toEqual($old->period_from)
        ->period_to->toEqual($old->period_to)
        ->total->toBe('472.00')
        ->and($new->lines[0]->sub_description)->toBe($old->lines[0]->sub_description)
        ->and($recharge->fresh())->invoice_id->toBe($new->id)->amount->toBe('472.00')
        ->and($advance->fresh()->invoice_id)->toBe($new->id);
});

it('refuses an invoice with payments or one already cancelled, and changes nothing', function () {
    $old = routerInvoice($this->account);
    app(RecordPayment::class)->handle($old->id, new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, '100', null, now(), null));
    $lines = [new LineInput('Router', null, null, '1', '900', '0', true)];

    expect(fn () => app(ReissueInvoice::class)->handle($old->id, $lines, false, now(), 'Wrong price'))->toThrow(InvoiceHasPayments::class)
        ->and($old->fresh()->status)->toBe(InvoiceStatus::PartlyPaid)
        ->and(Invoice::query()->count())->toBe(1);

    $cancelled = routerInvoice($this->account);
    $cancelled->update(['status' => InvoiceStatus::Cancelled]);

    expect(fn () => app(ReissueInvoice::class)->handle($cancelled->id, $lines, false, now(), 'Again'))->toThrow(InvoiceAlreadyCancelled::class)
        ->and(Invoice::query()->count())->toBe(2);
});

it('opens Cancel and reissue filled in from the invoice and issues the replacement from the Billing tab', function () {
    $old = routerInvoice($this->account);

    Livewire::test('pages::customers.billing-tab', ['accountId' => $this->account->id])
        ->assertSee(__('Cancel and reissue'))
        ->call('openReissueInvoice', $old->id)
        ->assertSet('reissuing_invoice_id', $old->id)
        ->assertSet('invoice_date', '2026-10-15')
        ->assertSet('lines', [['description' => 'Router', 'details' => 'Dual band', 'quantity' => '1', 'unit_price' => '1000.00',
            'discount' => '10', 'taxed' => true, 'item_code' => '8517', 'source' => 0]])
        ->call('reissueInvoice')
        ->assertHasErrors(['reissue_reason' => 'required'])
        ->set('lines.0.unit_price', '900')
        ->set('reissue_reason', 'Wrong price')
        ->call('reissueInvoice')
        ->assertHasNoErrors()
        ->assertSee('INV-2');

    expect($old->fresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and(Invoice::query()->where('replaces_invoice_id', $old->id)->sole()->total)->toBe('955.80');
});

it('keeps the discounts of the old invoice for staff who cannot set discounts', function () {
    $old = routerInvoice($this->account);
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices, Permission::CreateInvoices, Permission::CancelInvoices));

    Livewire::test('pages::invoices.show', ['invoice' => $old->id])
        ->call('openReissueInvoice', $old->id)
        ->set('lines.0.discount', '50')
        ->set('reissue_reason', 'Wrong address')
        ->call('reissueInvoice')
        ->assertHasNoErrors();

    expect(Invoice::query()->where('replaces_invoice_id', $old->id)->sole()->lines[0]->discount_percent)->toBe('10.00');
});

it('opens the new invoice after reissuing from the invoice page, and links the two', function () {
    $old = routerInvoice($this->account);

    Livewire::test('pages::invoices.show', ['invoice' => $old->id])
        ->call('openReissueInvoice', $old->id)
        ->set('reissue_reason', 'Wrong address')
        ->call('reissueInvoice')
        ->assertRedirect(route('invoices.show', Invoice::query()->where('number', 'INV-2')->sole()));

    $new = Invoice::query()->where('number', 'INV-2')->sole();

    expect(InvoiceView::for($new)['replaces'])->toBe('INV-1')
        ->and(InvoiceView::for($old->fresh())['replaced_by'])->toBe('INV-2');
    Livewire::test('pages::invoices.show', ['invoice' => $new->id])->assertSee('INV-1')->assertSeeHtml(route('invoices.show', $old));
    Livewire::test('pages::invoices.show', ['invoice' => $old->id])->assertSee('INV-2')->assertDontSee(__('Cancel and reissue'));
});

it('tells staff to remove the payments first', function () {
    $old = routerInvoice($this->account);
    app(RecordPayment::class)->handle($old->id, new PaymentInput(PaymentMethod::query()->where('name', 'Cash')->sole()->id, '100', null, now(), null));

    Livewire::test('pages::invoices.show', ['invoice' => $old->id])
        ->call('openReissueInvoice', $old->id)
        ->set('reissue_reason', 'Wrong price')
        ->call('reissueInvoice')
        ->assertHasErrors(['reissue_reason']);

    expect(Invoice::query()->count())->toBe(1);
});

it('offers Cancel and reissue only to staff who can both cancel and create invoices', function (Permission $missing) {
    $old = routerInvoice($this->account);
    $held = array_values(array_filter([Permission::ViewCustomers, Permission::ViewInvoices, Permission::CreateInvoices, Permission::CancelInvoices],
        fn (Permission $permission): bool => $permission !== $missing));
    $this->actingAs(staffHolding(...$held));

    Livewire::test('pages::invoices.show', ['invoice' => $old->id])
        ->assertDontSee(__('Cancel and reissue'))
        ->call('openReissueInvoice', $old->id)
        ->assertForbidden();
})->with([Permission::CreateInvoices, Permission::CancelInvoices]);
