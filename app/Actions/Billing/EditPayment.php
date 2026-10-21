<?php

namespace App\Actions\Billing;

use App\Billing\Pdf\ReceiptView;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Corrects how and when a payment came in: its method, reference, received at and staff note. The amount and the invoice stay
 * as they are (a wrong amount is removed and recorded again). Only the fields that changed are logged, old and new.
 */
final class EditPayment
{
    /**
     * How the activity log stores the received at (ActivityTimeline::STORED_DATE).
     */
    private const STORED_DATE = 'Y-m-d H:i';

    public function handle(int $paymentId, int $methodId, ?string $reference, CarbonInterface $paidAt, ?string $note = null): void
    {
        DB::transaction(function () use ($paymentId, $methodId, $reference, $paidAt, $note): void {
            $payment = Payment::query()->with(['method', 'invoice'])->lockForUpdate()->findOrFail($paymentId);
            $method = PaymentMethod::query()
                ->where(fn ($query) => $query->where('active', true)->orWhere('id', $payment->payment_method_id))
                ->findOrFail($methodId);
            $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
            $note = $note === null || trim($note) === '' ? null : trim($note);

            $old = ['method' => $payment->method?->name, 'reference' => $payment->reference, 'paid_at' => $payment->paid_at->format(self::STORED_DATE), 'note' => $payment->note];
            $new = ['method' => $method->name, 'reference' => $reference, 'paid_at' => $paidAt->format(self::STORED_DATE), 'note' => $note];
            $changed = array_keys(array_diff_assoc(array_map(strval(...), $new), array_map(strval(...), $old)));

            if ($changed === [] && $method->id === $payment->payment_method_id) {
                return;
            }

            $payment->update(['payment_method_id' => $method->id, 'reference' => $reference, 'paid_at' => $paidAt, 'note' => $note]);

            activity()
                ->performedOn($payment->account)
                ->event('payment-edited')
                ->withProperties(['receipt' => ReceiptView::number($payment), 'invoice' => $payment->invoice->number])
                ->withChanges([
                    'old' => array_intersect_key($old, array_flip($changed)),
                    'attributes' => array_intersect_key($new, array_flip($changed)),
                ])
                ->log('payment-edited');
        }, attempts: 3);
    }
}
