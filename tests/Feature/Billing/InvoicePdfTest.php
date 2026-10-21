<?php

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\IssueInvoice;
use App\Billing\Pdf\InvoiceView;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    AppSetting::put('date_format', 'd/m/Y');
    AppSetting::put('currency_symbol', '₹');
    turnInvoicingOn();
    $this->account = Account::factory()->create(['username' => 'alice', 'fullname' => 'Alice Example']);
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices));
});

function invoiceFor(Account $account, string $price = '500'): Invoice
{
    return app(IssueInvoice::class)->handle($account, billingLines($price), false, InvoiceKind::Manual, now());
}

it('renders a PDF', function () {
    $invoice = invoiceFor($this->account);

    $response = $this->get(route('invoices.pdf', $invoice));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain('INV-1.pdf')
        ->and(Activity::query()->where('event', 'invoice-downloaded')->sole()->attribute_changes['attributes']['invoice'])->toBe('INV-1');
});

it('refuses the PDF without View invoices', function () {
    $invoice = invoiceFor($this->account);
    $this->actingAs(staffHolding(Permission::ViewCustomers));

    $this->get(route('invoices.pdf', $invoice))->assertForbidden();
    $this->get(route('invoices.show', $invoice))->assertForbidden();
});

it('the PDF of an old invoice shows the old company name and rate', function () {
    $invoice = invoiceFor($this->account);
    AppSetting::put('billing.company', (string) json_encode(['name' => 'Renamed ISP', 'country' => 'IN']));
    AppSetting::put('billing.tax', (string) json_encode(['rate' => '12', 'state' => '09']));

    $view = InvoiceView::for($invoice->fresh());

    expect($view['company']['name'])->toBe('Example ISP')
        ->and(array_column($view['tax_lines'], 'label'))->toBe(['CGST 9%', 'SGST 9%'])
        ->and($view['total'])->toBe('₹590.00')
        ->and($view['total_in_words'])->toContain('Five Hundred Ninety');
});

it('marks a cancelled invoice', function () {
    $invoice = invoiceFor($this->account);
    app(CancelInvoice::class)->handle($invoice->id, 'Wrong customer');

    expect(InvoiceView::for($invoice->fresh()))
        ->cancelled->toBeTrue()
        ->cancel_reason->toBe('Wrong customer');
});

it('prints place of supply and bill period', function () {
    $this->account->update(['tax_id' => '07ABCDE1234F1Z5', 'place_of_supply' => '07']);
    $invoice = app(IssueInvoice::class)->handle($this->account->fresh(), billingLines('500'), false, InvoiceKind::Recharge, now(), null,
        Carbon::parse('2026-10-21 10:00'), Carbon::parse('2026-11-20 10:00'));

    $view = InvoiceView::for($invoice);

    expect($view['place_of_supply'])->toBe('Delhi (07)')
        ->and($view['bill_period'])->toBe('21/10/2026 to 20/11/2026')
        ->and(array_column($view['tax_lines'], 'label'))->toBe(['IGST 18%'])
        ->and($view['customer']['tax_label'])->toBe('GSTIN');
});

it('says TAX INVOICE only for India GST', function () {
    $india = invoiceFor($this->account);
    turnInvoicingOn('GB', 'single', ['name' => 'VAT', 'rate' => '20']);
    $uk = invoiceFor(Account::factory()->create());

    expect(InvoiceView::for($india)['title'])->toBe('TAX INVOICE')
        ->and(InvoiceView::for($uk)['title'])->toBe('INVOICE')
        ->and(InvoiceView::for($uk)['item_code_label'])->toBeNull();
});

it('shows the invoice page with its actions', function () {
    $invoice = invoiceFor($this->account);

    Livewire::test('pages::invoices.show', ['invoice' => $invoice->id])
        ->assertSee('INV-1')
        ->assertSee('Alice Example')
        ->assertSee('CGST 9%')
        ->assertSeeHtml(route('invoices.pdf', $invoice))
        ->assertDontSee(__('Record payment'));

    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices, Permission::RecordPayments));

    Livewire::test('pages::invoices.show', ['invoice' => $invoice->id])
        ->assertSee(__('Record payment'))
        ->call('openRecordPayment', $invoice->id)
        ->call('recordPayment')
        ->assertHasNoErrors();

    expect($invoice->fresh()->balance)->toBe('0.00');
});

it('links the invoice from the Billing and Recharges tabs', function () {
    $invoice = invoiceFor($this->account);

    Livewire::test('pages::customers.billing-tab', ['accountId' => $this->account->id])
        ->assertSeeHtml(route('invoices.show', $invoice));
});

it('leaves out an empty connectivity address and shows the status', function () {
    $invoice = invoiceFor($this->account);

    expect(InvoiceView::for($invoice)['customer']['connectivity_lines'])->toBe([]);
    Livewire::test('pages::invoices.show', ['invoice' => $invoice->id])
        ->assertDontSee(__('Connectivity address'))
        ->assertSee(__('Unpaid'));
    expect(view('pdf.invoice', ['invoice' => InvoiceView::for($invoice)])->render())->not->toContain('Connectivity address');
});
