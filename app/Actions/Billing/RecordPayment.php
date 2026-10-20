<?php

namespace App\Actions\Billing;

use App\Billing\Decimal;
use App\Billing\PaymentInput;
use App\Exceptions\PaymentExceedsBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * Records money received against an invoice. The invoice row is locked, so two staff taking the last payment at
 * once cannot both get it in.
 */
final class RecordPayment
{
    public function handle(int $invoiceId, PaymentInput $input): Payment
    {
        return DB::transaction(function () use ($invoiceId, $input): Payment {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
            $amount = Decimal::round($input->amount, 2);

            if (Decimal::compare($amount, '0') <= 0 || Decimal::compare($amount, (string) $invoice->balance) > 0) {
                throw new PaymentExceedsBalance("A payment must be more than nothing and at most {$invoice->balance}.");
            }

            $method = PaymentMethod::query()->findOrFail($input->methodId);

            $payment = $invoice->payments()->create([
                'account_id' => $invoice->account_id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'reference' => $input->reference === null || trim($input->reference) === '' ? null : trim($input->reference),
                'note' => $input->note === null || trim($input->note) === '' ? null : trim($input->note),
                'paid_at' => $input->paidAt,
                'user_id' => auth()->id(),
            ]);

            $invoice->refreshStatus();

            activity()
                ->performedOn($invoice->account)
                ->event('payment-recorded')
                ->withChanges(['old' => [], 'attributes' => [
                    'invoice' => $invoice->number,
                    'amount' => $amount,
                    'method' => $method->name,
                    'reference' => $payment->reference,
                    'note' => $payment->note,
                ]])
                ->log('payment-recorded');

            return $payment;
        }, attempts: 3);
    }
}
