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
    /** End service: whatever its state. */
    public const ANY = 'any';

    /** Release IP now: only while it is still past paid-until (a renewal meanwhile wins). */
    public const LAPSED = 'lapsed';

    /** The scheduler: only while past paid-until and past its hold. */
    public const HOLD_ENDED = 'hold-ended';

    public function __construct(private SyncAccountToRadius $sync) {}

    /**
     * @param  string  $event  service-ip-released or service-ended
     * @param  self::ANY|self::LAPSED|self::HOLD_ENDED  $when  checked under the lock, so a renewal that committed
     *                                                         after the caller looked keeps the IP
     * @return ?bool true when a static IP still in use (paid, not lapsed) was taken away: the caller disconnects the
     *               customer (StaticIpReleased) after the commit; null when $when no longer holds and nothing changed
     */
    public function handle(CustomerService $service, string $reason, string $event, string $when = self::ANY): ?bool
    {
        return DB::transaction(function () use ($service, $reason, $event, $when): ?bool {
            $account = Account::query()->withTrashed()->lockForUpdate()->findOrFail($service->account_id);
            $service = CustomerService::query()->with('item', 'ip')->lockForUpdate()->findOrFail($service->id);

            if ($service->ended_at !== null) {
                return false;
            }

            $lapsed = ! $service->paid_until->greaterThan(now());

            if (($when === self::LAPSED && ! $lapsed)
                || ($when === self::HOLD_ENDED && (! $lapsed || $service->held_until === null || $service->held_until->greaterThan(now())))) {
                return null;
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
