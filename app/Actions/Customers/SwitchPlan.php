<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Enums\RechargeKind;
use App\Enums\ServiceType;
use App\Exceptions\CustomerNotExpired;
use App\Exceptions\FirstLoginPlanNeedsHotspot;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Recharge;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Gives an expired or Inactive customer another plan before they renew: no charge, no invoice, no new expiry, no
 * usage reset and no message. It is recorded as a recharge of the kind SwitchPlan (amount 0) so the history stays
 * in one place, and the FreeRADIUS rows are rebuilt for the new plan. The next Recharge then starts on it.
 */
final class SwitchPlan
{
    public function __construct(private SyncAccountToRadius $sync) {}

    /**
     * @throws ModelNotFoundException when the customer or the plan was deleted meanwhile
     * @throws CustomerNotExpired when the customer has time left (recharged meanwhile)
     * @throws FirstLoginPlanNeedsHotspot when a first-login plan is chosen for a PPPoE or IPoE customer
     */
    public function handle(int $accountId, int $planId, ?string $note): Recharge
    {
        return DB::transaction(function () use ($accountId, $planId, $note): Recharge {
            $account = Account::query()->with('plan')->lockForUpdate()->findOrFail($accountId);

            if (! self::allowed($account)) {
                throw new CustomerNotExpired;
            }

            $plan = Plan::query()->sharedLock()->findOrFail($planId);

            if ($plan->hotspot_starts_at_first_login && $account->service_type !== ServiceType::Hotspot) {
                throw new FirstLoginPlanNeedsHotspot;
            }

            $oldPlan = $account->plan;

            $account->disableLogging();
            $account->update(['plan_id' => $plan->id]);
            $this->sync->handle($account);

            $recharge = Recharge::query()->create([
                'account_id' => $account->id,
                'plan_id' => $plan->id,
                'old_plan_id' => $oldPlan?->id,
                'kind' => RechargeKind::SwitchPlan,
                'old_expiry' => $account->expiry,
                'new_expiry' => $account->expiry,
                'old_status' => $account->status_id,
                'amount' => '0',
                'note' => $note,
                'user_id' => auth()->id(),
            ]);

            activity()
                ->performedOn($account)
                ->event(RechargeKind::SwitchPlan->event())
                ->withChanges([
                    'old' => ['plan' => $oldPlan?->name],
                    'attributes' => ['plan' => $plan->name, 'note' => $note],
                ])
                ->log(RechargeKind::SwitchPlan->event());

            return $recharge;
        }, attempts: 3);
    }

    /**
     * Inactive, or an expiry that has passed with no first-login validity waiting.
     */
    public static function allowed(Account $account): bool
    {
        if ($account->status_id === AccountStatus::Inactive) {
            return true;
        }

        return $account->hotspot_pending_validity === null && $account->expiry !== null && $account->expiry->lessThanOrEqualTo(now());
    }
}
