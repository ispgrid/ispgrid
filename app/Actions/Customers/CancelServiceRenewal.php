<?php

namespace App\Actions\Customers;

use App\Actions\Billing\CancelInvoice;
use App\Exceptions\CannotCancelServiceRenewal;
use App\Exceptions\InvoiceHasPayments;
use App\Models\Account;
use App\Models\CustomerService;
use App\Models\CustomerServiceRenewal;
use Illuminate\Support\Facades\DB;

/**
 * Undoes the latest renewal of a customer service, like Cancel recharge: paid-until goes back, its invoice is
 * cancelled (refused while it has payments) and the row stays, marked. When paid-until is then in the past, the
 * service lapses at once; a static IP is dropped from FreeRADIUS and held from now, not from the old date, so a
 * wrong renewal never costs the customer their IP. The row Add service wrote cannot be cancelled here.
 */
class CancelServiceRenewal
{
    public function __construct(private CancelInvoice $cancelInvoice, private LapseCustomerService $lapse) {}

    /**
     * @return bool true when a static IP lapsed by the cancel: the caller disconnects the customer (StaticIpExpired)
     *              after the commit
     */
    public function handle(int $renewalId, string $reason): bool
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($renewalId, $reason): bool {
            // Account, then service, then renewal: the order the other service actions lock in.
            $serviceId = (int) CustomerServiceRenewal::query()->whereKey($renewalId)->valueOrFail('customer_service_id');
            $accountId = (int) CustomerService::query()->whereKey($serviceId)->valueOrFail('account_id');
            $account = Account::query()->withTrashed()->lockForUpdate()->findOrFail($accountId);
            $service = CustomerService::query()->with('item')->lockForUpdate()->findOrFail($serviceId);
            $renewal = CustomerServiceRenewal::query()->lockForUpdate()->findOrFail($renewalId);

            if ($renewal->cancelled_at !== null || $service->ended_at !== null || $service->latestRenewal()->value('id') !== $renewal->id) {
                throw new CannotCancelServiceRenewal('not-latest');
            }

            if ($renewal->old_paid_until === null) {
                throw new CannotCancelServiceRenewal('first');
            }

            if ($renewal->invoice_id !== null) {
                try {
                    $this->cancelInvoice->handle($renewal->invoice_id, "Service renewal cancelled: {$reason}");
                } catch (InvoiceHasPayments) {
                    throw new CannotCancelServiceRenewal('payments');
                }
            }

            $before = $service->paid_until;
            $service->update(['paid_until' => $renewal->old_paid_until]);
            $renewal->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);

            activity()
                ->performedOn($account)
                ->event('service-renewal-cancelled')
                ->withChanges([
                    'old' => ['paid_until' => $before->format(RechargeAccount::DATE_FORMAT)],
                    'attributes' => ['service' => $service->item->name, 'paid_until' => $service->paid_until->format(RechargeAccount::DATE_FORMAT), 'reason' => $reason],
                ])
                ->log('service-renewal-cancelled');

            return ! $service->paid_until->greaterThan(now()) && $this->lapse->handle($service, now());
        }, attempts: 3);
    }
}
