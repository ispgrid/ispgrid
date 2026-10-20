<?php

namespace App\Actions\Customers;

use App\Enums\AdvanceRenewalStatus;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Cancels the customer's waiting advance renewal, if any. Nothing is recorded as money.
 */
final class CancelAdvanceRenewal
{
    public function handle(int $accountId): void
    {
        DB::transaction(function () use ($accountId): void {
            $account = Account::query()->withTrashed()->lockForUpdate()->findOrFail($accountId);
            self::cancelWaiting($account);
        }, attempts: 3);
    }

    /**
     * Cancels and logs it inside the caller's transaction (the account row is locked).
     */
    public static function cancelWaiting(Account $account, ?string $reason = null): void
    {
        $waiting = $account->waitingRenewal()->with('plan')->first();

        if ($waiting === null) {
            return;
        }

        $waiting->update(['status' => AdvanceRenewalStatus::Cancelled]);

        activity()
            ->performedOn($account)
            ->event('advance-cancelled')
            ->withChanges([
                'old' => ['plan' => $waiting->plan?->name, 'starts' => $waiting->starts_at->format(RechargeAccount::DATE_FORMAT)],
                'attributes' => array_filter(['reason' => $reason]),
            ])
            ->log('advance-cancelled');
    }
}
