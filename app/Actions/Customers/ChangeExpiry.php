<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Models\Account;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Sets the customer's expiry without a recharge (no money recorded) and moves a waiting advance renewal with it.
 * A validity waiting for the first login is replaced by the date.
 */
final class ChangeExpiry
{
    public function __construct(private SyncAccountToRadius $sync, private MoveWaitingRenewal $moveWaiting) {}

    /**
     * @return bool whether an online customer must be disconnected: the new expiry is less than a minute away (or
     *              past), so the shorter limit has to apply now; a later one applies at the next login
     *
     * @throws ModelNotFoundException when the customer was deleted meanwhile
     */
    public function handle(int $accountId, CarbonInterface $expiry, ?string $note): bool
    {
        DB::transaction(function () use ($accountId, $expiry, $note): void {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            $oldExpiry = $account->expiry;

            $account->disableLogging();
            $account->update(['expiry' => $expiry, 'hotspot_pending_validity' => null]);
            $this->sync->handle($account);
            $this->moveWaiting->handle($account, $oldExpiry);

            activity()
                ->performedOn($account)
                ->event('expiry-changed')
                ->withChanges([
                    'old' => ['expiry' => $oldExpiry?->format(RechargeAccount::DATE_FORMAT)],
                    'attributes' => ['expiry' => $expiry->format(RechargeAccount::DATE_FORMAT), 'note' => $note],
                ])
                ->log('expiry-changed');
        }, attempts: 3);

        return $expiry->lessThan(now()->addMinute());
    }
}
