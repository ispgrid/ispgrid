<?php

namespace App\Actions\Billing;

use App\Billing\PaymentProof;
use App\Billing\Pdf\ReceiptView;
use App\Enums\PaymentProofChange;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Corrects how and when a payment came in: its method, reference, received at and staff note. The amount and the invoice stay
 * as they are (a wrong amount is removed and recorded again). The screenshot kept with the payment can be added, replaced
 * or removed (the old file is deleted). Only the fields that changed are logged, old and new.
 */
final class EditPayment
{
    /**
     * How the activity log stores the received at (ActivityTimeline::STORED_DATE).
     */
    private const STORED_DATE = 'Y-m-d H:i';

    public function handle(int $paymentId, int $methodId, ?string $reference, CarbonInterface $paidAt, ?string $note = null, ?UploadedFile $proof = null, bool $removeProof = false): void
    {
        $stored = null;

        try {
            $this->save($paymentId, $methodId, $reference, $paidAt, $note, $proof, $removeProof, $stored);
        } catch (Throwable $failure) {
            PaymentProof::delete($stored);

            throw $failure;
        }
    }

    /**
     * @param  ?string  $stored  set to the new file's path as soon as it is stored, so a failed save can delete it
     */
    private function save(int $paymentId, int $methodId, ?string $reference, CarbonInterface $paidAt, ?string $note, ?UploadedFile $proof, bool $removeProof, ?string &$stored): void
    {
        DB::transaction(function () use ($paymentId, $methodId, $reference, $paidAt, $note, $proof, $removeProof, &$stored): void {
            $payment = Payment::query()->with(['method', 'invoice'])->lockForUpdate()->findOrFail($paymentId);

            if ($payment->method?->gateway !== null) {
                throw new InvalidArgumentException('A payment made online is not edited.');
            }
            $method = PaymentMethod::query()->forStaff()
                ->where(fn ($query) => $query->where('active', true)->orWhere('id', $payment->payment_method_id))
                ->findOrFail($methodId);
            $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
            $note = $note === null || trim($note) === '' ? null : trim($note);

            $old = ['method' => $payment->method?->name, 'reference' => $payment->reference, 'paid_at' => $payment->paid_at->format(self::STORED_DATE), 'note' => $payment->note];
            $new = ['method' => $method->name, 'reference' => $reference, 'paid_at' => $paidAt->format(self::STORED_DATE), 'note' => $note];
            $changed = array_keys(array_diff_assoc(array_map(strval(...), $new), array_map(strval(...), $old)));

            $oldProof = $payment->proof_path;
            $newProof = $oldProof;

            if ($proof !== null) {
                $newProof = $stored = PaymentProof::store($payment->id, $proof, unique: true);
            } elseif ($removeProof) {
                $newProof = null;
            }

            if ($newProof !== $oldProof || $proof !== null) {
                $old['screenshot'] = $oldProof === null ? null : PaymentProofChange::OnFile->value;
                $new['screenshot'] = ($newProof === null ? PaymentProofChange::Removed : ($oldProof === null ? PaymentProofChange::Added : PaymentProofChange::Replaced))->value;
                $changed[] = 'screenshot';
            }

            if ($changed === [] && $method->id === $payment->payment_method_id) {
                return;
            }

            $payment->update(['payment_method_id' => $method->id, 'reference' => $reference, 'paid_at' => $paidAt, 'note' => $note, 'proof_path' => $newProof]);

            if ($oldProof !== null && $oldProof !== $newProof) {
                DB::afterCommit(fn () => rescue(fn () => PaymentProof::delete($oldProof), report: true));
            }

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
