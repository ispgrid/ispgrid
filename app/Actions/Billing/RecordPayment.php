<?php

namespace App\Actions\Billing;

use App\Billing\Decimal;
use App\Billing\PaymentInput;
use App\Billing\PaymentProof;
use App\Exceptions\PaymentExceedsBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records money received against an invoice. The invoice row is locked, so two staff taking the last payment at
 * once cannot both get it in.
 */
final class RecordPayment
{
    public function handle(int $invoiceId, PaymentInput $input): Payment
    {
        $stored = null;

        try {
            return $this->record($invoiceId, $input, $stored);
        } catch (Throwable $failure) {
            PaymentProof::delete($stored);

            throw $failure;
        }
    }

    /**
     * @param  ?string  $stored  set to the screenshot's path as soon as it is stored, so a rolled back payment can delete it
     */
    private function record(int $invoiceId, PaymentInput $input, ?string &$stored): Payment
    {
        return DB::transaction(function () use ($invoiceId, $input, &$stored): Payment {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
            $amount = Decimal::round($input->amount, Currency::decimals());

            if (Decimal::compare($amount, '0') <= 0 || Decimal::compare($amount, (string) $invoice->balance) > 0) {
                throw new PaymentExceedsBalance("A payment must be more than nothing and at most {$invoice->balance}.");
            }

            $method = PaymentMethod::query()->where('active', true)
                ->when(! $input->online, fn ($query) => $query->whereNull('gateway'))
                ->findOrFail($input->methodId);

            $payment = $invoice->payments()->create([
                'account_id' => $invoice->account_id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'reference' => $input->reference === null || trim($input->reference) === '' ? null : trim($input->reference),
                'note' => $input->note === null || trim($input->note) === '' ? null : trim($input->note),
                'paid_at' => $input->paidAt,
                'user_id' => auth()->id(),
            ]);

            if ($input->proof !== null && ! $input->online) {
                $payment->update(['proof_path' => $stored = PaymentProof::store($payment->id, $input->proof)]);
            }

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
