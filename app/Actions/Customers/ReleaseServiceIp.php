<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Models\Account;
use App\Models\CustomerService;
use Illuminate\Support\Facades\DB;

/**
 * Ends a customer service: End service, Release IP now, or the hold running out. A static IP goes back to the free
 * pool at once (the customer's fixed IP is cleared when the service still owns it) and the customer's rows are
 * written again. The row stays for history.
 */
class ReleaseServiceIp
{
    public function __construct(private SyncAccountToRadius $sync) {}

    /**
     * @param  string  $event  service-ip-released or service-ended
     * @return bool true when a static IP still in use (paid, not lapsed) was taken away: the caller disconnects the
     *              customer (StaticIpReleased) after the commit
     */
    public function handle(CustomerService $service, string $reason, string $event): bool
    {
        return DB::transaction(function () use ($service, $reason, $event): bool {
            $account = Account::query()->withTrashed()->lockForUpdate()->findOrFail($service->account_id);
            $service = CustomerService::query()->with('item', 'ip')->lockForUpdate()->findOrFail($service->id);

            if ($service->ended_at !== null) {
                return false;
            }

            $static = $service->isStaticIp();
            $inUse = $static && $service->paid_until->greaterThan(now());
            $service->update(['ended_at' => now(), 'ended_by' => auth()->id(), 'end_reason' => $reason]);

            if ($static && $service->ip_id !== null && $account->ip_id === $service->ip_id) {
                $previousIpId = $account->ip_id;
                $account->disableLogging();
                $account->update(['ip_id' => null]);
                $this->sync->handle($account, $previousIpId);
            }

            activity()
                ->performedOn($account)
                ->event($event)
                ->withChanges(['attributes' => [
                    'service' => $service->item->name, 'reason' => $reason, ...($static ? ['ip' => $service->ip?->address] : []),
                ]])
                ->log($event);

            return $inUse && ! $account->trashed();
        }, attempts: 3);
    }
}
