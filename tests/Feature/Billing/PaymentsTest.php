<?php

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\RemovePayment;
use App\Billing\PaymentInput;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPayments;
use App\Exceptions\PaymentExceedsBalance;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->staff = User::factory()->create();
    $this->actingAs($this->staff);
    turnInvoicingOn();
});

/**
 * An unpaid invoice of 590.00 (500 plus 18% GST).
 */
function unpaidInvoice(): Invoice
{
    $account = Account::factory()->create();

    return app(IssueInvoice::class)->handle($account, billingDraft($account, '500.00'), InvoiceKind::Manual, now());
}

function pay(Invoice $invoice, string $amount, string $method = 'Cash'): Payment
{
    $methodId = PaymentMethod::query()->where('name', $method)->value('id');

    return app(RecordPayment::class)->handle($invoice->id, new PaymentInput($methodId, $amount, 'REF1', now(), null));
}

it('marks an invoice Partly paid then Paid', function () {
    $invoice = unpaidInvoice();

    pay($invoice, '200.00');
    expect($invoice->refresh())->status->toBe(InvoiceStatus::PartlyPaid)->paid_total->toBe('200.00')->balance->toBe('390.00');

    $payment = pay($invoice, '390.00', 'UPI');
    expect($invoice->refresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe('0.00')
        ->and($payment->user_id)->toBe($this->staff->id)
        ->and($payment->account_id)->toBe($invoice->account_id)
        ->and(Activity::query()->where('event', 'payment-recorded')->count())->toBe(2);
});

it('a payment above the balance is refused', function () {
    $invoice = unpaidInvoice();
    pay($invoice, '500.00');

    pay($invoice, '90.01');
})->throws(PaymentExceedsBalance::class);

it('refuses a payment of nothing', function () {
    pay(unpaidInvoice(), '0');
})->throws(PaymentExceedsBalance::class);

it('removing a payment reopens the balance', function () {
    $invoice = unpaidInvoice();
    $payment = pay($invoice, '590.00');

    app(RemovePayment::class)->handle($payment->id, 'Cheque bounced');

    expect($invoice->refresh())->status->toBe(InvoiceStatus::Unpaid)->balance->toBe('590.00')
        ->and(Payment::withTrashed()->find($payment->id))->remove_reason->toBe('Cheque bounced')->removed_by->toBe($this->staff->id)->trashed()->toBeTrue()
        ->and(Activity::query()->where('event', 'payment-removed')->exists())->toBeTrue();
});

it('refuses to cancel an invoice with payments', function () {
    $invoice = unpaidInvoice();
    pay($invoice, '100.00');

    app(CancelInvoice::class)->handle($invoice->id, 'Wrong customer');
})->throws(InvoiceHasPayments::class);

it('cancels with a reason and keeps the number', function () {
    $invoice = unpaidInvoice();

    app(CancelInvoice::class)->handle($invoice->id, 'Wrong customer');

    expect($invoice->refresh())->status->toBe(InvoiceStatus::Cancelled)
        ->number->toBe('INV-1')
        ->balance->toBe('0.00')
        ->cancel_reason->toBe('Wrong customer')
        ->cancelled_by->toBe($this->staff->id)
        ->and(unpaidInvoice()->number)->toBe('INV-2');
});

it('cancels an invoice whose payments were all removed', function () {
    $invoice = unpaidInvoice();
    app(RemovePayment::class)->handle(pay($invoice, '100.00')->id, 'Typed the wrong amount');

    app(CancelInvoice::class)->handle($invoice->id, 'Wrong customer');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled);
});

it('takes no payment for a cancelled invoice', function () {
    $invoice = unpaidInvoice();
    app(CancelInvoice::class)->handle($invoice->id, 'Wrong customer');

    pay($invoice, '10.00');
})->throws(PaymentExceedsBalance::class);
