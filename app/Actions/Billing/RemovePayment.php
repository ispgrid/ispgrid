<?php

namespace App\Actions\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Removes a payment recorded by mistake (or a cheque that bounced). The row stays, marked removed with who did it
 * and why, and the invoice owes the amount again.
 */
final class RemovePayment
{
    public function handle(int $paymentId, string $reason): void
    {
        DB::transaction(function () use ($paymentId, $reason): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail(Payment::query()->findOrFail($paymentId)->invoice_id);
            $payment = Payment::query()->with('method')->findOrFail($paymentId);

            $payment->update(['removed_by' => auth()->id(), 'remove_reason' => trim($reason)]);
            $payment->delete();
            $invoice->refreshStatus();

            activity()
                ->performedOn($invoice->account)
                ->event('payment-removed')
                ->withChanges(['old' => [], 'attributes' => [
                    'invoice' => $invoice->number,
                    'amount' => $payment->amount,
                    'method' => $payment->method?->name,
                    'reason' => trim($reason),
                ]])
                ->log('payment-removed');
        }, attempts: 3);
    }
}
