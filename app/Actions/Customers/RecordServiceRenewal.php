<?php

namespace App\Actions\Customers;

use App\Actions\Billing\IssueInvoice;
use App\Billing\BillingSettings;
use App\Billing\InvoicePolicy;
use App\Billing\PaymentInput;
use App\Billing\ServiceInvoice;
use App\Enums\InvoiceKind;
use App\Models\Account;
use App\Models\CustomerService;
use App\Models\CustomerServiceRenewal;
use Carbon\CarbonInterface;

/**
 * Writes one sale of a customer service (Add service or a renewal) and its invoice, the way recharges are charged:
 * an invoice while the customer is invoiced (the row then keeps the invoice total), otherwise the amount alone (0
 * for a Free customer). Runs inside the caller's transaction.
 */
class RecordServiceRenewal
{
    public function __construct(private IssueInvoice $issueInvoice) {}

    public function handle(CustomerService $service, Account $account, int $months, string $amount, ?CarbonInterface $oldPaidUntil, CarbonInterface $from, ?PaymentInput $payment): CustomerServiceRenewal
    {
        $renewal = CustomerServiceRenewal::query()->create([
            'customer_service_id' => $service->id,
            'months' => $months,
            'amount' => $account->billing_free ? '0' : $amount,
            'old_paid_until' => $oldPaidUntil,
            'new_paid_until' => $service->paid_until,
            'created_by' => auth()->id(),
        ]);

        if (InvoicePolicy::invoicesRecharge($account, BillingSettings::current())) {
            $invoice = $this->issueInvoice->handle($account, ServiceInvoice::lines($service, $service->item, $amount, $from, $service->paid_until),
                false, InvoiceKind::Service, now(), $payment, $from, $service->paid_until, customerServiceId: $service->id);
            $renewal->update(['invoice_id' => $invoice->id, 'amount' => $invoice->total]);
        }

        return $renewal;
    }
}
