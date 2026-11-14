<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Billing\PaymentInput;
use App\Models\Account;
use App\Models\CustomerService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Renew a customer service for N calendar months: from its paid-until date while that is still ahead, else from now
 * (the customer did not have it in between, as with an expired plan). A static IP renewed after it lapsed gets the
 * same IP back: the hold is cleared and FreeRADIUS sends the address again. Issues the renewal's own invoice.
 */
class RenewCustomerService
{
    public function __construct(private SyncAccountToRadius $sync, private RecordServiceRenewal $record) {}

    /**
     * @return bool true when a lapsed static IP came back: the caller disconnects the customer (StaticIpRenewed)
     *              after the commit, so the next login gets the fixed IP
     */
    public function handle(CustomerService $service, int $months, string $amount, ?PaymentInput $payment): bool
    {
        return DB::transaction(function () use ($service, $months, $amount, $payment): bool {
            $account = Account::query()->withTrashed()->lockForUpdate()->findOrFail($service->account_id);
            $service = CustomerService::query()->with('item', 'ip')->lockForUpdate()->findOrFail($service->id);

            if ($service->ended_at !== null || $account->trashed()) {
                throw new InvalidArgumentException('An ended service cannot be renewed.');
            }

            $old = $service->paid_until;
            $from = $old->greaterThan(now()) ? $old->copy() : now();
            $lapsedStaticIp = $service->isStaticIp() && ! $old->greaterThan(now());

            $service->update(['paid_until' => $from->copy()->addMonthsNoOverflow($months), 'expired_at' => null, 'held_until' => null]);

            if ($lapsedStaticIp) {
                $this->sync->handle($account);
            }

            $renewal = $this->record->handle($service, $account, $months, $amount, $old, $from, $payment);

            activity()
                ->performedOn($account)
                ->event('service-renewed')
                ->withChanges([
                    'old' => ['paid_until' => $old->format(RechargeAccount::DATE_FORMAT)],
                    'attributes' => [
                        'service' => $service->item->name, 'months' => $months, 'paid_until' => $service->paid_until->format(RechargeAccount::DATE_FORMAT),
                        'amount' => (string) $renewal->amount, ...($service->ip === null ? [] : ['ip' => $service->ip->address]),
                    ],
                ])
                ->log('service-renewed');

            return $lapsedStaticIp;
        }, attempts: 3);
    }
}
