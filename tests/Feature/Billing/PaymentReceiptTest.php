<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\RemovePayment;
use App\Billing\PaymentInput;
use App\Billing\Pdf\ReceiptView;
use App\Enums\InvoiceKind;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    AppSetting::put('date_format', 'd/m/Y');
    AppSetting::put('currency_symbol', '₹');
    turnInvoicingOn();
    $this->account = Account::factory()->create(['username' => 'alice', 'fullname' => 'Alice Example']);
    $this->invoice = app(IssueInvoice::class)->handle($this->account, billingLines('500'), false, InvoiceKind::Manual, now());
    $this->actingAs(staffHolding(Permission::ViewCustomers, Permission::ViewInvoices, Permission::RecordPayments));
});

function paymentOn(Invoice $invoice, string $amount, string $method = 'UPI', ?string $reference = 'UPI-1'): Payment
{
    $methodId = PaymentMethod::query()->where('name', $method)->sole()->id;

    return app(RecordPayment::class)->handle($invoice->id, new PaymentInput($methodId, $amount, $reference, now(), null));
}

it('downloads a receipt and logs it', function () {
    $payment = paymentOn($this->invoice, '300');

    $response = $this->get(route('payments.receipt', $payment));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain("RCPT-{$payment->id}.pdf")
        ->and(Activity::query()->where('event', 'receipt-downloaded')->sole()->attribute_changes['attributes'])
        ->toEqual(['receipt' => "RCPT-{$payment->id}", 'invoice' => 'INV-1']);
});

it('refuses the receipt without View invoices', function () {
    $payment = paymentOn($this->invoice, '300');
    $this->actingAs(staffHolding(Permission::ViewCustomers));

    $this->get(route('payments.receipt', $payment))->assertForbidden();
});

it('prints the payment, its words and the balance left after it', function () {
    $first = paymentOn($this->invoice, '300');
    $this->travel(1)->hours();
    $second = paymentOn($this->invoice, '290', 'Cash', null);

    $view = ReceiptView::for($first->fresh());

    expect($view)
        ->number->toBe("RCPT-{$first->id}")
        ->title->toBe('PAYMENT RECEIPT')
        ->date->toBe('21/10/2026 10:00')
        ->method->toBe('UPI')
        ->reference->toBe('UPI-1')
        ->amount->toBe('₹300.00')
        ->amount_in_words->toBe('Indian Rupee Three Hundred Only')
        ->invoice_number->toBe('INV-1')
        ->invoice_total->toBe('₹590.00')
        ->balance_after->toBe('₹290.00')
        ->removed->toBeFalse()
        ->and($view['company']['name'])->toBe('Example ISP')
        ->and($view['customer']['username'])->toBe('alice')
        ->and(ReceiptView::for($second->fresh())['balance_after'])->toBe('₹0.00');
});

it('marks a removed payment and leaves it out of later balances', function () {
    $first = paymentOn($this->invoice, '300');
    $this->travel(1)->hours();
    $second = paymentOn($this->invoice, '100', 'Cash', null);
    app(RemovePayment::class)->handle($first->id, 'Bounced');

    $removed = ReceiptView::for(Payment::withTrashed()->findOrFail($first->id));

    expect($removed['removed'])->toBeTrue()
        ->and($removed['remove_reason'])->toBe('Bounced')
        ->and(ReceiptView::for($second->fresh())['balance_after'])->toBe('₹490.00');
    $this->get(route('payments.receipt', $first->id))->assertOk();
});

it('links the receipt from every list of payments', function () {
    $payment = paymentOn($this->invoice, '300');
    $receipt = route('payments.receipt', $payment);

    Livewire::test('pages::invoices.show', ['invoice' => $this->invoice->id])->assertSeeHtml($receipt);
    Livewire::test('pages::customers.billing-tab', ['accountId' => $this->account->id])->assertSeeHtml($receipt);
    Livewire::test('pages::payments.index')->assertSeeHtml($receipt);
});
