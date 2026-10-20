<?php

namespace App\Actions\Customers;

use App\Models\Account;
use Carbon\CarbonInterface;

/**
 * A waiting advance renewal always starts at the customer's expiry: when the expiry changes before it starts
 * (recharge, change of plan, change of expiry), its start and end move by the same amount. Runs inside the
 * caller's transaction, after the account's new expiry is saved.
 */
final class MoveWaitingRenewal
{
    public function handle(Account $account, ?CarbonInterface $oldExpiry): void
    {
        $waiting = $account->waitingRenewal()->first();
        $newExpiry = $account->expiry;

        if ($waiting === null || $oldExpiry === null || $newExpiry === null || $oldExpiry->equalTo($newExpiry)) {
            return;
        }

        $seconds = (int) $oldExpiry->diffInSeconds($newExpiry, false);
        $oldStart = $waiting->starts_at;

        $waiting->update([
            'starts_at' => $waiting->starts_at->copy()->addSeconds($seconds),
            'expiry' => $waiting->expiry->copy()->addSeconds($seconds),
        ]);

        activity()
            ->performedOn($account)
            ->event('advance-moved')
            ->withChanges([
                'old' => ['starts' => $oldStart->format(RechargeAccount::DATE_FORMAT)],
                'attributes' => ['starts' => $waiting->starts_at->format(RechargeAccount::DATE_FORMAT)],
            ])
            ->log('advance-moved');
    }
}
