<?php

namespace App\Livewire\Concerns;

use App\Actions\Billing\CancelInvoice;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\RemovePayment;
use App\Billing\BillingSettings;
use App\Exceptions\InvoiceHasPayments;
use App\Exceptions\PaymentExceedsBalance;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Payment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Record payment, Remove payment and Cancel invoice on one customer's invoices (the Billing tab and the invoice
 * page; their dialogs are in partials/invoice-dialogs). Needs TakesPayment and an $accountId; the component says
 * what happens after a change in invoiceChanged(). Nothing changes on a deleted customer. While invoicing is off no
 * new payment is taken, but Remove payment and Cancel invoice still fix mistakes (and free a waiting advance renewal).
 */
trait ManagesInvoices
{
    /**
     * The invoice a payment is being recorded for, the payment being removed and the invoice being cancelled.
     */
    #[Locked]
    public ?int $paying_invoice_id = null;

    #[Locked]
    public ?int $removing_payment_id = null;

    #[Locked]
    public ?int $cancelling_invoice_id = null;

    public string $remove_reason = '';

    public string $cancel_reason = '';

    /**
     * After an invoice or payment changed: close the dialog, refresh what the page shows and say so.
     */
    abstract protected function invoiceChanged(string $dialog, string $message): void;

    /**
     * The balance of the invoice a payment is being recorded for.
     */
    #[Computed]
    public function payingBalance(): string
    {
        return $this->paying_invoice_id === null ? '0' : (string) $this->ownInvoice($this->paying_invoice_id)->balance;
    }

    public function openRecordPayment(int $invoiceId): void
    {
        $this->authorizeLive('record-payments');
        abort_unless(BillingSettings::current()->enabled, 403);
        $invoice = $this->ownInvoice($invoiceId);
        $this->resetValidation();
        $this->paying_invoice_id = $invoice->id;
        unset($this->payingBalance);
        $this->resetPayment();
        $this->dispatch('modal-open', name: 'billing-record-payment');
    }

    public function recordPayment(RecordPayment $record): void
    {
        $this->authorizeLive('record-payments');
        abort_unless(BillingSettings::current()->enabled, 403);
        abort_if($this->paying_invoice_id === null, 404);
        $balance = $this->payingBalance;
        $this->paid_now = true;

        $this->validate($this->paymentRules($balance), [], $this->paymentAttributes());
        $payment = $this->paymentInput($balance);

        if ($payment === null) {
            $this->invoiceChanged('billing-record-payment', __('Nothing is due on this invoice.'));

            return;
        }

        try {
            $record->handle($this->paying_invoice_id, $payment);
        } catch (PaymentExceedsBalance) {
            unset($this->payingBalance);
            $this->addError('payment_amount', __('The payment must be more than 0 and at most the total.'));

            return;
        }

        unset($this->payingBalance);
        $this->invoiceChanged('billing-record-payment', __('Payment recorded.'));
    }

    public function openRemovePayment(int $paymentId): void
    {
        $this->authorizeLive('record-payments');
        $this->resetValidation();
        $this->removing_payment_id = Payment::query()->where('account_id', $this->accountId)->findOrFail($paymentId)->id;
        $this->remove_reason = '';
        $this->dispatch('modal-open', name: 'billing-remove-payment');
    }

    public function removePayment(RemovePayment $remove): void
    {
        $this->authorizeLive('record-payments');
        abort_if($this->removing_payment_id === null, 404);
        $this->remove_reason = trim($this->remove_reason);
        $this->validate(['remove_reason' => ['required', 'string', 'max:255']], [], ['remove_reason' => __('reason')]);

        $remove->handle(Payment::query()->where('account_id', $this->accountId)->findOrFail($this->removing_payment_id)->id, $this->remove_reason);
        $this->invoiceChanged('billing-remove-payment', __('Payment removed.'));
    }

    public function openCancelInvoice(int $invoiceId): void
    {
        $this->authorizeLive('cancel-invoices');
        $this->resetValidation();
        $this->cancelling_invoice_id = $this->ownInvoice($invoiceId)->id;
        $this->cancel_reason = '';
        $this->dispatch('modal-open', name: 'billing-cancel-invoice');
    }

    public function cancelInvoice(CancelInvoice $cancel): void
    {
        $this->authorizeLive('cancel-invoices');
        abort_if($this->cancelling_invoice_id === null, 404);
        $this->cancel_reason = trim($this->cancel_reason);
        $this->validate(['cancel_reason' => ['required', 'string', 'max:255']], [], ['cancel_reason' => __('reason')]);

        try {
            $cancel->handle($this->ownInvoice($this->cancelling_invoice_id)->id, $this->cancel_reason);
        } catch (InvoiceHasPayments) {
            $this->addError('cancel_reason', __('This invoice has payments. Remove them first.'));

            return;
        }

        $this->invoiceChanged('billing-cancel-invoice', __('Invoice cancelled.'));
    }

    /**
     * The action's permission, and a customer that is not deleted.
     */
    protected function authorizeLive(string $ability): void
    {
        $this->authorize($ability);
        abort_if(Account::onlyTrashed()->whereKey($this->accountId)->exists(), 403);
    }

    /**
     * One of this customer's invoices (404 for anyone else's).
     */
    protected function ownInvoice(int $invoiceId): Invoice
    {
        return Invoice::query()->where('account_id', $this->accountId)->findOrFail($invoiceId);
    }
}
