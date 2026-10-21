<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPayments;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an invoice issued by mistake. It keeps its number (the series stays without gaps) and owes nothing.
 * One with payments is refused: remove them first.
 */
final class CancelInvoice
{
    /**
     * @param  ?string  $replacedBy  the number of the invoice that replaces it (Cancel and reissue)
     */
    public function handle(int $invoiceId, string $reason, ?string $replacedBy = null): void
    {
        DB::transaction(function () use ($invoiceId, $reason, $replacedBy): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->status === InvoiceStatus::Cancelled) {
                return;
            }

            if ($invoice->payments()->exists()) {
                throw new InvoiceHasPayments("Invoice {$invoice->number} has payments.");
            }

            $invoice->update([
                'status' => InvoiceStatus::Cancelled,
                'balance' => '0',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => trim($reason),
            ]);

            activity()
                ->performedOn($invoice->account)
                ->event('invoice-cancelled')
                ->withChanges(['old' => [], 'attributes' => array_filter(['invoice' => $invoice->number, 'total' => $invoice->total, 'reason' => trim($reason), 'replaced_by' => $replacedBy])])
                ->log('invoice-cancelled');
        }, attempts: 3);
    }
}
