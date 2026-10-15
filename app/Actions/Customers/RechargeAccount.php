<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Enums\ValidityUnit;
use App\Exceptions\ExpiryChangedMeanwhile;
use App\Exceptions\FirstLoginPlanNeedsHotspot;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Recharge;
use App\Notifications\Notifier;
use App\Notifications\Triggers\RechargeDone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A recharge (spec section 5): records it, gives the customer the plan, the new expiry and a fresh usage period
 * (Isp-Usage-Since), makes an Inactive customer Active and rebuilds their FreeRADIUS rows, all in one transaction,
 * and queues the recharge message. The caller queues the disconnect after the commit.
 * A first-login plan on a Hotspot customer whose clock is not running leaves its validity waiting for the first
 * login instead (hotspot_pending_validity, no expiry); FreeRADIUS starts it.
 */
final class RechargeAccount
{
    /**
     * How the activity log shows the expiry.
     */
    public const DATE_FORMAT = 'Y-m-d H:i';

    public function __construct(private SyncAccountToRadius $sync, private Notifier $notifier) {}

    /**
     * @param  ?CarbonInterface  $expiry  the new expiry; ignored when the validity waits for the first login
     * @param  ?string  $expectedExpiry  the expiryKey() the new expiry was computed from; null skips the check
     *
     * @throws ModelNotFoundException when the customer or the plan was deleted meanwhile
     * @throws ExpiryChangedMeanwhile when the customer's expiry is no longer $expectedExpiry
     * @throws FirstLoginPlanNeedsHotspot when a first-login plan is chosen for a PPPoE or IPoE customer
     */
    public function handle(int $accountId, int $planId, ?CarbonInterface $expiry, string $amount, ?string $note, ?string $expectedExpiry = null): Recharge
    {
        // Loaded inside the transaction: on a deadlock retry, a model updated by the rolled-back attempt would
        // look unchanged and skip its UPDATE while the sync still writes the new values.
        return DB::transaction(function () use ($accountId, $planId, $expiry, $amount, $note, $expectedExpiry): Recharge {
            $account = Account::query()->with('plan')->lockForUpdate()->findOrFail($accountId);

            if ($expectedExpiry !== null && self::expiryKey($account) !== $expectedExpiry) {
                throw new ExpiryChangedMeanwhile;
            }

            $plan = Plan::query()->findOrFail($planId);

            if ($plan->hotspot_starts_at_first_login && $account->service_type !== ServiceType::Hotspot) {
                throw new FirstLoginPlanNeedsHotspot;
            }

            $pending = self::pendingValidity($account, $plan);

            if ($pending === null && $expiry === null) {
                throw new InvalidArgumentException('This recharge sets an expiry, and none was given.');
            }

            $oldPlan = $account->plan?->name;
            $oldExpiry = $account->expiry;
            $oldPending = $account->hotspot_pending_validity;
            $oldStatus = $account->status_id;
            $newExpiry = $pending === null ? $expiry : null;

            // One "Recharged" entry below says what changed; the model's own entry would repeat it.
            $account->disableLogging();
            $account->update([
                'plan_id' => $plan->id,
                'expiry' => $newExpiry,
                'hotspot_pending_validity' => $pending,
                ...($pending === null ? [] : ['hotspot_first_login_at' => null]),
                'usage_since' => now(),
                'status_id' => $oldStatus === AccountStatus::Inactive ? AccountStatus::Active : $oldStatus,
            ]);
            $this->sync->handle($account);

            $recharge = Recharge::query()->create([
                'account_id' => $account->id,
                'plan_id' => $plan->id,
                'old_expiry' => $oldExpiry,
                'new_expiry' => $newExpiry,
                'new_pending_validity' => $pending,
                'amount' => $amount,
                'note' => $note,
                'user_id' => auth()->id(),
            ]);

            // Rows now, inside the transaction; the send jobs are queued after the commit.
            $this->notifier->fire(RechargeDone::key(), $account, $recharge);

            activity()
                ->performedOn($account)
                ->event('recharged')
                ->withChanges([
                    'old' => ['plan' => $oldPlan, 'expiry' => self::expiryText($oldExpiry, $oldPending)],
                    'attributes' => ['plan' => $plan->name, 'expiry' => self::expiryText($newExpiry, $pending), 'amount' => $amount, 'note' => $note],
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

    /**
     * The minutes a recharge with this plan leaves waiting for the first login, or null when it sets an expiry.
     * Only a first-login plan on a Hotspot customer whose clock is not running waits; a validity still waiting
     * grows (two 3-hour sales wait as 6 hours). A running clock is extended like any recharge.
     */
    public static function pendingValidity(Account $account, Plan $plan): ?int
    {
        if (! $plan->hotspot_starts_at_first_login || $account->service_type !== ServiceType::Hotspot) {
            return null;
        }

        if ($account->expiry !== null && $account->expiry->greaterThan(now())) {
            return null;
        }

        return ($account->hotspot_pending_validity ?? 0) + $plan->validityMinutes();
    }

    /**
     * The account's expiry as the recharge dialog remembers it: 'Y-m-d H:i:s', 'pending:<minutes>' while the
     * validity waits for the first login, or '' for none. FreeRADIUS stamping the first login changes it.
     */
    public static function expiryKey(Account $account): string
    {
        if ($account->hotspot_pending_validity !== null) {
            return 'pending:'.$account->hotspot_pending_validity;
        }

        return $account->expiry?->format('Y-m-d H:i:s') ?? '';
    }

    /**
     * How the activity log shows an expiry, or a validity waiting for the first login.
     */
    private static function expiryText(?CarbonInterface $expiry, ?int $pending): ?string
    {
        return $pending !== null ? ValidityUnit::firstLoginLabel($pending) : $expiry?->format(self::DATE_FORMAT);
    }
}
