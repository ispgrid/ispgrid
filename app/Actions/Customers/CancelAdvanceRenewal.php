<?php

namespace App\Actions\Customers;

use App\Actions\Billing\CancelInvoice;
use App\Enums\AdvanceRenewalStatus;
use App\Exceptions\RenewalHasPayments;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Cancels the customer's waiting advance renewal, if any, and the invoice issued at booking. One whose invoice has
 * payments is refused: there are no refunds yet, so staff remove the payments first.
 */
final class CancelAdvanceRenewal
{
    /**
     * @throws RenewalHasPayments
     */
    public function handle(int $accountId): void
    {
        DB::transaction(function () use ($accountId): void {
            $account = Account::query()->withTrashed()->lockForUpdate()->findOrFail($accountId);
            self::cancelWaiting($account);
        }, attempts: 3);
    }

    /**
     * Cancels and logs it inside the caller's transaction (the account row is locked).
     *
     * @throws RenewalHasPayments
     */
    public static function cancelWaiting(Account $account, ?string $reason = null): void
    {
        $waiting = $account->waitingRenewal()->with('plan')->first();

        if ($waiting === null) {
            return;
        }

        if ($waiting->invoice_id !== null) {
            if ($waiting->invoice?->payments()->exists()) {
                throw new RenewalHasPayments("The advance renewal's invoice {$waiting->invoice->number} has payments.");
            }

            app(CancelInvoice::class)->handle($waiting->invoice_id, 'Advance renewal cancelled');
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
