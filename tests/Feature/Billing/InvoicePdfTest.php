<?php

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\IssueInvoice;
use App\Billing\Pdf\InvoiceView;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Address;
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

it('leaves out an empty installation address and shows the status', function () {
    $invoice = invoiceFor($this->account);

    expect(InvoiceView::for($invoice)['customer']['connectivity_lines'])->toBe([]);
    Livewire::test('pages::invoices.show', ['invoice' => $invoice->id])
        ->assertDontSee(__('Installation address'))
        ->assertSee(__('Unpaid'));
    expect(view('pdf.invoice', ['invoice' => InvoiceView::for($invoice)])->render())->not->toContain('Installation address');
});

it('shows the unit price before tax when prices include tax', function (string $quantity, string $discount, string $rate, ?string $discountText, string $amount) {
    AppSetting::put('billing.prices_include_tax', '1');
    $invoice = app(IssueInvoice::class)->handle($this->account, billingLines('2499', $quantity, $discount), false, InvoiceKind::Manual, now());

    $line = InvoiceView::for($invoice->fresh())['lines'][0];

    expect([$line['rate'], $line['discount'], $line['amount']])->toBe([$rate, $discountText, $amount]);
})->with([
    'one, no discount' => ['1', '0', '₹2,117.80', null, '₹2,117.80'],
    'one, 10% off' => ['1', '10', '₹2,117.80', '₹211.78 (10%)', '₹1,906.02'],
    'two, no discount' => ['2', '0', '₹2,117.80', null, '₹4,235.59'],
]);

it('keeps the unit price as entered when prices do not include tax', function () {
    $line = InvoiceView::for(invoiceFor($this->account, '2499')->fresh())['lines'][0];

    expect([$line['rate'], $line['amount']])->toBe(['₹2,499.00', '₹2,499.00']);
});

it('prints the invoice title from the settings on new invoices only', function () {
    $old = invoiceFor($this->account);
    AppSetting::put('billing.invoice_title', 'INVOICE-CUM-RECEIPT');
    $new = invoiceFor($this->account);

    expect(InvoiceView::for($old->fresh())['title'])->toBe('TAX INVOICE')
        ->and(InvoiceView::for($new)['title'])->toBe('INVOICE-CUM-RECEIPT');
});

it('keeps the tax setup title when the setting is empty', function () {
    turnInvoicingOn('GB', 'single', ['name' => 'VAT', 'rate' => '20']);
    AppSetting::put('billing.invoice_title', '');

    expect(InvoiceView::for(invoiceFor($this->account))['title'])->toBe('INVOICE');
});

it('prints the PIN code after the state', function () {
    AppSetting::put('billing.company', (string) json_encode(['name' => 'Example ISP', 'country' => 'IN', 'address_lines' => ['1 Example Street'],
        'city' => 'Prayagraj', 'state' => 'Uttar Pradesh', 'postal_code' => '211001']));
    $address = Address::factory()->create(['line1' => '12 MG Road', 'area' => null, 'city' => 'Bengaluru', 'postal_code' => '560001', 'state' => 'Karnataka', 'country' => 'India']);
    $this->account->update(['address_id' => $address->id]);

    $view = InvoiceView::for(invoiceFor($this->account->fresh()));

    expect($view['customer']['billing_lines'])->toBe(['Alice Example', '12 MG Road', 'Bengaluru', 'Karnataka 560001'])
        ->and($view['customer']['connectivity_lines'])->toBe(['12 MG Road', 'Bengaluru', 'Karnataka 560001'])
        ->and($view['company']['lines'])->toBe(['1 Example Street', 'Prayagraj', 'Uttar Pradesh 211001', 'India']);
});

it('keeps the address lines an invoice was issued with', function () {
    $invoice = invoiceFor($this->account);
    $invoice->update(['customer' => array_merge($invoice->customer, ['billing_lines' => ['Alice Example', 'Bengaluru 560001', 'Karnataka']])]);

    expect(InvoiceView::for($invoice->fresh())['customer']['billing_lines'])->toBe(['Alice Example', 'Bengaluru 560001', 'Karnataka']);
});

it('labels the installation address and the username', function () {
    $address = Address::factory()->create(['line1' => '12 MG Road', 'city' => 'Bengaluru', 'postal_code' => '560001', 'state' => 'Karnataka', 'country' => 'India']);
    $this->account->update(['address_id' => $address->id]);
    $invoice = invoiceFor($this->account->fresh());

    Livewire::test('pages::invoices.show', ['invoice' => $invoice->id])
        ->assertSee(__('Installation address'))
        ->assertDontSee('Connectivity address');
    $html = view('pdf.invoice', ['invoice' => InvoiceView::for($invoice)])->render();
    expect($html)->toContain('Installation address')->toContain('Username')
        ->not->toContain('Connectivity address')->not->toContain('Customer ID');
});

it('puts the logo on the right and the title in its own centred row', function () {
    $html = view('pdf.invoice', ['invoice' => InvoiceView::for(invoiceFor($this->account))])->render();

    expect($html)->toMatch('/<td class="center title-row"[^>]*>\s*<div class="title">TAX INVOICE<\/div>/');
});
