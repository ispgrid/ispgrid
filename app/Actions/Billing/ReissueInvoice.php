<?php

namespace App\Actions\Billing;

use App\Billing\LineInput;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceAlreadyCancelled;
use App\Exceptions\InvoiceHasPayments;
use App\Models\AdvanceRenewal;
use App\Models\Invoice;
use App\Models\Recharge;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cancel and reissue: cancels an invoice issued wrong and issues its replacement in one go. The new invoice gets the
 * next number and a fresh copy of the customer and company, and keeps the old one's kind, recharge, bill period and
 * validity. A recharge or advance renewal billed on the old invoice moves to the new one. One with payments is
 * refused, like Cancel: remove them first.
 */
final class ReissueInvoice
{
    public function __construct(private IssueInvoice $issue, private CancelInvoice $cancel) {}

    /**
     * @param  list<LineInput>  $lines
     */
    public function handle(int $invoiceId, array $lines, bool $roundOff, CarbonInterface $issueDate, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoiceId, $lines, $roundOff, $issueDate, $reason): Invoice {
            $old = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

            if ($old->status === InvoiceStatus::Cancelled) {
                throw new InvoiceAlreadyCancelled("Invoice {$old->number} is already cancelled.");
            }

            if ($old->payments()->exists()) {
                throw new InvoiceHasPayments("Invoice {$old->number} has payments.");
            }

            $new = $this->issue->handle($old->account, $lines, $roundOff, $old->kind, $issueDate, null, $old->period_from,
                $old->period_to, $old->validity_text, $old->recharge_id, $old);
            $this->cancel->handle($old->id, $reason, $new->number);

            Recharge::query()->where('invoice_id', $old->id)->update(['invoice_id' => $new->id, 'amount' => $new->total]);
            AdvanceRenewal::query()->where('invoice_id', $old->id)->update(['invoice_id' => $new->id, 'amount' => $new->total]);

            return $new;
        }, attempts: 3);
    }
}
