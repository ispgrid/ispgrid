<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Billing\BillingSettings;
use App\Models\Account;
use App\Models\CustomerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A customer service passed its paid-until date: marked expired once (expired_at). A static IP is then held for the
 * Billing setting's days counted from paid-until (or from $holdFrom, when a cancelled renewal takes the time away),
 * fixed now so a later settings change does not move it; with the setting at 0 it is held until staff release it.
 * The customer's rows are written again without the fixed IP.
 */
class LapseCustomerService
{
    public function __construct(private SyncAccountToRadius $sync) {}

    /**
     * @return bool true for a static IP that lapsed now: the caller disconnects the customer (StaticIpExpired) after
     *              the commit, so the next login takes a pool address
     */
    public function handle(CustomerService $service, ?CarbonInterface $holdFrom = null): bool
    {
        return DB::transaction(function () use ($service, $holdFrom): bool {
            $account = Account::query()->withTrashed()->lockForUpdate()->findOrFail($service->account_id);
            $service = CustomerService::query()->with('item', 'ip')->lockForUpdate()->findOrFail($service->id);

            if ($service->ended_at !== null || $service->expired_at !== null || $service->paid_until->greaterThan(now())) {
                return false;
            }

            $static = $service->isStaticIp();
            $days = BillingSettings::current()->staticIpHoldDays;
            $service->update([
                'expired_at' => now(),
                'held_until' => $static && $days > 0 ? ($holdFrom ?? $service->paid_until)->copy()->addDays($days) : null,
            ]);

            if ($static) {
                $this->sync->handle($account);
            }

            activity()
                ->performedOn($account)
                ->event('service-expired')
                ->withChanges(['attributes' => [
                    'service' => $service->item->name, 'paid_until' => $service->paid_until->format(RechargeAccount::DATE_FORMAT),
                    ...($static ? ['ip' => $service->ip?->address, 'held_until' => $service->held_until?->format(RechargeAccount::DATE_FORMAT) ?? __('Until released')] : []),
                ]])
                ->log('service-expired');

            return $static && ! $account->trashed();
        }, attempts: 3);
    }
}
