<?php

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RecordPayment;
use App\Billing\PaymentInput;
use App\Enums\InvoiceKind;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
    AppSetting::put('date_format', 'd/m/Y');
    AppSetting::put('currency_symbol', '₹');
    turnInvoicingOn();
    turnSelfcareOn();
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->selfcare()->create(['username' => 'kushal']);
    $this->invoice = selfcareInvoice($this->account);
    $this->payment = selfcarePayment($this->invoice, '300');
});

function selfcareInvoice(Account $account, string $price = '500'): Invoice
{
    return app(IssueInvoice::class)->handle($account, billingLines($price), false, InvoiceKind::Manual, now());
}

function selfcarePayment(Invoice $invoice, string $amount): Payment
{
    $methodId = PaymentMethod::query()->where('name', 'UPI')->sole()->id;

    return app(RecordPayment::class)->handle($invoice->id, new PaymentInput($methodId, $amount, 'UPI-1', now(), null));
}

it('lists the customer\'s invoices and receipts', function () {
    actingAsCustomer($this->account);

    $this->get(route('selfcare.dashboard'))->assertOk()
        ->assertSee(__('Bills'))
        ->assertSee($this->invoice->number)
        ->assertSee(route('selfcare.invoice', $this->invoice))
        ->assertSee('RCPT-'.$this->payment->id)
        ->assertSee(route('selfcare.receipt', $this->payment));
});

it('downloads an own invoice and receipt as PDF', function () {
    actingAsCustomer($this->account);

    $invoice = $this->get(route('selfcare.invoice', $this->invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $receipt = $this->get(route('selfcare.receipt', $this->payment))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(substr((string) $invoice->getContent(), 0, 4))->toBe('%PDF')
        ->and($invoice->headers->get('Content-Disposition'))->toContain($this->invoice->number.'.pdf')
        ->and(substr((string) $receipt->getContent(), 0, 4))->toBe('%PDF')
        ->and($invoice->headers->get('Cache-Control'))->toContain('no-store');

    $logged = Activity::query()->whereIn('event', ['invoice-downloaded', 'receipt-downloaded'])->get();
    expect($logged)->toHaveCount(2)
        ->and($logged->pluck('causer_id')->unique()->all())->toBe([$this->account->id])
        ->and($logged->pluck('causer_type')->unique()->all())->toBe([Account::class]);
});

it('404s on another customer\'s invoice or receipt', function () {
    $other = Account::factory()->create();
    $otherInvoice = selfcareInvoice($other);
    $otherPayment = selfcarePayment($otherInvoice, '100');
    actingAsCustomer($this->account);

    $this->get(route('selfcare.invoice', $otherInvoice))->assertNotFound();
    $this->get(route('selfcare.receipt', $otherPayment))->assertNotFound();
    $this->get('/selfcare/invoices/999999')->assertNotFound();
    $this->get(route('selfcare.dashboard'))->assertDontSee($otherInvoice->number);
});

it('hides cancelled invoices and removed payments', function () {
    $cancelled = selfcareInvoice($this->account, '200');
    app(CancelInvoice::class)->handle($cancelled->id, 'Wrong amount');
    $removed = selfcarePayment($this->invoice, '50');
    $removed->delete();
    actingAsCustomer($this->account);

    $this->get(route('selfcare.invoice', $cancelled))->assertNotFound();
    $this->get(route('selfcare.receipt', $removed->id))->assertNotFound();
    $this->get(route('selfcare.dashboard'))->assertDontSee($cancelled->number)->assertDontSee('RCPT-'.$removed->id);
});

it('hides bills while invoicing is off', function () {
    AppSetting::put('billing.enabled', '0');
    actingAsCustomer($this->account);

    $this->get(route('selfcare.dashboard'))->assertOk()->assertDontSee(__('Bills'));
    $this->get(route('selfcare.invoice', $this->invoice))->assertNotFound();
});

it('needs a selfcare login', function () {
    $this->get(route('selfcare.invoice', $this->invoice))->assertRedirect(route('selfcare.login'));
    $this->get(route('selfcare.receipt', $this->payment))->assertRedirect(route('selfcare.login'));
});
