<?php

namespace App\Actions\Customers;

use App\Actions\Billing\CancelInvoice;
use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Enums\RechargeKind;
use App\Enums\ValidityUnit;
use App\Exceptions\CannotCancelRecharge;
use App\Exceptions\InvoiceHasPayments;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Recharge;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a wrong recharge (the customer's latest): the old expiry, plan, first-login validity and Inactive status
 * come back, its invoice is cancelled, and the row stays in the history marked cancelled (it drops out of totals).
 * The FreeRADIUS rows are rebuilt in the same transaction. The usage period is not put back: data used stays counted.
 * The caller disconnects an online customer after the commit when this returns true.
 */
final class CancelRecharge
{
    public function __construct(private SyncAccountToRadius $sync, private CancelInvoice $cancelInvoice) {}

    /**
     * @return bool whether to disconnect: the old expiry has passed (or there is none), the plan changed, or the
     *              customer is Inactive again
     *
     * @throws CannotCancelRecharge when it is not the latest recharge, its invoice has payments, an advance renewal
     *                              waits, or it was recorded before cancelling was possible
     * @throws ModelNotFoundException when the recharge or the customer is gone
     */
    public function handle(int $rechargeId, string $reason): bool
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($rechargeId, $reason): bool {
            $accountId = Recharge::query()->whereKey($rechargeId)->valueOrFail('account_id');
            $account = Account::query()->with('plan')->lockForUpdate()->findOrFail($accountId);
            $recharge = Recharge::query()->lockForUpdate()->findOrFail($rechargeId);

            if ($recharge->old_status === null && self::isLatest($recharge)) {
                throw new CannotCancelRecharge('too-old');
            }

            if (! self::cancellable($recharge)) {
                throw new CannotCancelRecharge('not-latest');
            }

            if ($account->waitingRenewal()->exists()) {
                throw new CannotCancelRecharge('advance-waiting');
            }

            if ($recharge->invoice_id !== null) {
                try {
                    $this->cancelInvoice->handle($recharge->invoice_id, "Recharge cancelled: {$reason}");
                } catch (InvoiceHasPayments) {
                    throw new CannotCancelRecharge('payments');
                }
            }

            $before = ['plan' => $account->plan?->name, 'expiry' => self::expiryText($account->expiry, $account->hotspot_pending_validity)];
            $oldPlanId = $account->plan_id;
            $oldStatus = $account->status_id;
            $status = $recharge->old_status === AccountStatus::Inactive && $oldStatus === AccountStatus::Active ? AccountStatus::Inactive : $oldStatus;

            $account->disableLogging();
            $account->update([
                'plan_id' => $recharge->old_plan_id,
                'expiry' => $recharge->old_expiry,
                'hotspot_pending_validity' => $recharge->old_pending_validity,
                'status_id' => $status,
            ]);
            $this->sync->handle($account);

            $recharge->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);

            $plan = $recharge->old_plan_id === null ? null : Plan::withTrashed()->find($recharge->old_plan_id);

            activity()
                ->performedOn($account)
                ->event('recharge-cancelled')
                ->withChanges([
                    'old' => $before,
                    'attributes' => [
                        'plan' => $plan?->name,
                        'expiry' => self::expiryText($recharge->old_expiry, $recharge->old_pending_validity),
                        'reason' => $reason,
                    ],
                ])
                ->log('recharge-cancelled');

            if ($status !== $oldStatus) {
                activity()
                    ->performedOn($account)
                    ->event('status-changed')
                    ->withChanges([
                        'old' => ['status' => $oldStatus->label()],
                        'attributes' => ['status' => $status->label(), 'remark' => __('Recharge cancelled')],
                    ])
                    ->log('status-changed');
            }

            return $recharge->old_expiry === null || $recharge->old_expiry->lessThanOrEqualTo(now())
                || $recharge->old_plan_id !== $oldPlanId || $status !== $oldStatus;
        }, attempts: 3);
    }

    /**
     * The customer's newest recharge, not cancelled, not a plan switch, and recorded since cancelling was possible
     * (it knows the old plan and status).
     */
    public static function cancellable(Recharge $recharge): bool
    {
        return $recharge->cancelled_at === null && $recharge->kind !== RechargeKind::SwitchPlan
            && $recharge->old_status !== null && self::isLatest($recharge);
    }

    private static function isLatest(Recharge $recharge): bool
    {
        return (int) Recharge::query()->where('account_id', $recharge->account_id)->max('id') === $recharge->id;
    }

    private static function expiryText(?CarbonInterface $expiry, ?int $pending): ?string
    {
        return $pending !== null ? ValidityUnit::firstLoginLabel($pending) : $expiry?->format(RechargeAccount::DATE_FORMAT);
    }
}
