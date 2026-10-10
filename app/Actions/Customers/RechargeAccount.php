<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Recharge;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * A recharge (spec section 5): records it, gives the customer the plan, the new expiry and a fresh usage period
 * (Isp-Usage-Since), makes an Inactive customer Active and rebuilds their FreeRADIUS rows, all in one transaction.
 * The caller queues the disconnect after the commit.
 */
final class RechargeAccount
{
    /**
     * How the activity log shows the expiry.
     */
    public const DATE_FORMAT = 'Y-m-d H:i';

    public function __construct(private SyncAccountToRadius $sync) {}

    /**
     * @throws ModelNotFoundException when the customer or the plan was deleted meanwhile
     */
    public function handle(int $accountId, int $planId, CarbonInterface $expiry, string $amount, ?string $note): Recharge
    {
        // Loaded inside the transaction: on a deadlock retry, a model updated by the rolled-back attempt would
        // look unchanged and skip its UPDATE while the sync still writes the new values.
        return DB::transaction(function () use ($accountId, $planId, $expiry, $amount, $note): Recharge {
            $account = Account::query()->with('plan')->lockForUpdate()->findOrFail($accountId);
            $plan = Plan::query()->findOrFail($planId);
            $oldPlan = $account->plan?->name;
            $oldExpiry = $account->expiry;
            $oldStatus = $account->status_id;

            // One "Recharged" entry below says what changed; the model's own entry would repeat it.
            $account->disableLogging();
            $account->update([
                'plan_id' => $plan->id,
                'expiry' => $expiry,
                'usage_since' => now(),
                'status_id' => $oldStatus === AccountStatus::Inactive ? AccountStatus::Active : $oldStatus,
            ]);
            $this->sync->handle($account);

            $recharge = Recharge::query()->create([
                'account_id' => $account->id,
                'plan_id' => $plan->id,
                'old_expiry' => $oldExpiry,
                'new_expiry' => $expiry,
                'amount' => $amount,
                'note' => $note,
                'user_id' => auth()->id(),
            ]);

            activity()
                ->performedOn($account)
                ->event('recharged')
                ->withChanges([
                    'old' => ['plan' => $oldPlan, 'expiry' => $oldExpiry?->format(self::DATE_FORMAT)],
                    'attributes' => ['plan' => $plan->name, 'expiry' => $expiry->format(self::DATE_FORMAT), 'amount' => $amount, 'note' => $note],
                ])
                ->log('recharged');

            if ($account->status_id !== $oldStatus) {
                activity()
                    ->performedOn($account)
                    ->event('status-changed')
                    ->withChanges([
                        'old' => ['status' => $oldStatus->label()],
                        'attributes' => ['status' => $account->status_id->label(), 'remark' => __('Recharge')],
                    ])
                    ->log('status-changed');
            }

            return $recharge;
        }, attempts: 3);
    }
}
