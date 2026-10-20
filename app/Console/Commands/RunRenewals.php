<?php

namespace App\Console\Commands;

use App\Actions\Customers\RechargeAccount;
use App\Actions\Customers\SetOverrideSpeed;
use App\Enums\AccountStatus;
use App\Enums\AdvanceRenewalStatus;
use App\Enums\RechargeKind;
use App\Exceptions\ExpiryChangedMeanwhile;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\Plan;
use App\Support\ExpiryCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Runs every minute. FreeRADIUS gives a session the time left as Session-Timeout, so a renewal runs up to
 * 10 minutes before the expiry: when the session ends at the old expiry, the customer reconnects at once on the new
 * period, which starts at the old expiry (also when the scheduler was down and the renewal is late). Each renewal
 * locks the account and checks the expiry it read, so an overlapping run or a staff recharge cannot record it twice.
 * In order: waiting advance renewals, auto renewals, override speeds that end, plans that expired with nothing renewing
 * them (logged once per expiry). One account failing never stops the others.
 */
#[Signature('customers:renewals')]
#[Description('Start advance renewals, auto renew customers, end override speeds and log expired plans')]
class RunRenewals extends Command
{
    /**
     * How long before the expiry a renewal runs.
     */
    public const MINUTES_AHEAD = 10;

    public function handle(RechargeAccount $recharge, SetOverrideSpeed $overrideSpeed): int
    {
        $now = CarbonImmutable::now();
        $until = $now->addMinutes(self::MINUTES_AHEAD);

        $this->startWaitingRenewals($recharge, $until);
        $this->autoRenew($recharge, $now, $until);
        $this->endOverrideSpeeds($overrideSpeed, $now);
        $this->logExpired($now);

        return self::SUCCESS;
    }

    private function startWaitingRenewals(RechargeAccount $recharge, CarbonImmutable $until): void
    {
        AdvanceRenewal::query()
            ->where('status', AdvanceRenewalStatus::Waiting)
            ->whereHas('account', fn ($query) => $query->where('expiry', '<=', $until))
            ->with(['account', 'plan'])
            ->chunkById(100, function ($renewals) use ($recharge): void {
                foreach ($renewals as $renewal) {
                    $this->safely(fn () => $this->startWaiting($recharge, $renewal));
                }
            });
    }

    private function startWaiting(RechargeAccount $recharge, AdvanceRenewal $renewal): void
    {
        $account = $renewal->account;

        if ($account->trashed()) {
            $renewal->update(['status' => AdvanceRenewalStatus::Cancelled]);

            return;
        }

        if ($renewal->plan->trashed()) {
            DB::transaction(function () use ($renewal, $account): void {
                $renewal->update(['status' => AdvanceRenewalStatus::Cancelled]);
                $this->logSkipped($account, __('The plan :name was deleted; the advance renewal is cancelled', ['name' => $renewal->plan->name]));
            });

            return;
        }

        DB::transaction(function () use ($recharge, $renewal, $account): void {
            $locked = AdvanceRenewal::query()->lockForUpdate()->find($renewal->id);

            if ($locked?->status !== AdvanceRenewalStatus::Waiting) {
                return;
            }

            $done = $recharge->handle(
                $account->id, $locked->plan_id, $locked->expiry, (string) $locked->amount, $locked->note,
                RechargeAccount::expiryKey($account), RechargeKind::Advance, $locked->user_id,
            );

            $locked->update(['status' => AdvanceRenewalStatus::Started, 'recharge_id' => $done->id]);
        });
    }

    private function autoRenew(RechargeAccount $recharge, CarbonImmutable $now, CarbonImmutable $until): void
    {
        Account::query()
            ->where('auto_renew', true)
            ->whereNotNull('expiry')
            ->where('expiry', '<=', $until)
            ->whereDoesntHave('waitingRenewal')
            ->chunkById(100, function ($accounts) use ($recharge, $now): void {
                foreach ($accounts as $account) {
                    $this->safely(fn () => $this->autoRenewOne($recharge, $account, $now));
                }
            });
    }

    private function autoRenewOne(RechargeAccount $recharge, Account $account, CarbonImmutable $now): void
    {
        $plan = $account->plan_id === null ? null : Plan::withTrashed()->find($account->plan_id);
        $oldExpiry = CarbonImmutable::instance($account->expiry);
        $expiry = $plan === null ? null
            : ExpiryCalculator::fromSettings()->next($oldExpiry, $plan->validity, $plan->validity_unit, $oldExpiry->subSecond());
        $reason = $this->cannotAutoRenew($account, $plan, $expiry, $now);

        if ($reason !== null) {
            $this->logSkippedOnce($account, $reason);

            return;
        }

        $recharge->handle($account->id, $plan->id, $expiry, (string) $plan->price, null, RechargeAccount::expiryKey($account), RechargeKind::Auto);
    }

    /**
     * Why the customer is not auto renewed, or null when they are.
     */
    private function cannotAutoRenew(Account $account, ?Plan $plan, ?CarbonImmutable $expiry, CarbonImmutable $now): ?string
    {
        if ($account->status_id !== AccountStatus::Active) {
            return __('The customer is :status', ['status' => $account->status_id->label()]);
        }

        if ($plan === null) {
            return __('The customer has no plan');
        }

        if ($plan->trashed()) {
            return __('The plan :name was deleted', ['name' => $plan->name]);
        }

        if ($plan->hotspot_starts_at_first_login) {
            return __('The plan starts at the first login');
        }

        if ($expiry === null || $expiry->lessThanOrEqualTo($now)) {
            return __('The plan ended too long ago; recharge by hand');
        }

        return null;
    }

    private function endOverrideSpeeds(SetOverrideSpeed $overrideSpeed, CarbonImmutable $now): void
    {
        Account::query()
            ->where('override_plan', true)
            ->whereNotNull('override_until')
            ->where('override_until', '<=', $now)
            ->chunkById(100, function ($accounts) use ($overrideSpeed): void {
                foreach ($accounts as $account) {
                    $this->safely(function () use ($overrideSpeed, $account): void {
                        $overrideSpeed->handle($account->id, null, null, null, null, 'own-speed-ended');
                        DisconnectAccount::dispatchIfOnline($account->username);
                    });
                }
            });
    }

    /**
     * Active customers whose expiry passed in the last day, with nothing renewing them: a "Plan expired" entry,
     * once per expiry.
     */
    private function logExpired(CarbonImmutable $now): void
    {
        Account::query()
            ->where('status_id', AccountStatus::Active)
            ->whereBetween('expiry', [$now->subDay(), $now])
            ->chunkById(100, function ($accounts): void {
                foreach ($accounts as $account) {
                    $expiry = $account->expiry->format(RechargeAccount::DATE_FORMAT);

                    if ($this->loggedFor($account, 'expired', $expiry)) {
                        continue;
                    }

                    $plan = $account->plan_id === null ? null : Plan::withTrashed()->find($account->plan_id);

                    activity()
                        ->performedOn($account)
                        ->event('expired')
                        ->withChanges(['old' => [], 'attributes' => ['plan' => $plan?->name, 'expiry' => $expiry]])
                        ->log('expired');
                }
            });
    }

    private function logSkippedOnce(Account $account, string $reason): void
    {
        if (! $this->loggedFor($account, 'auto-renew-skipped', $account->expiry->format(RechargeAccount::DATE_FORMAT))) {
            $this->logSkipped($account, $reason);
        }
    }

    private function logSkipped(Account $account, string $reason): void
    {
        activity()
            ->performedOn($account)
            ->event('auto-renew-skipped')
            ->withChanges(['old' => [], 'attributes' => ['reason' => $reason, 'expiry' => $account->expiry?->format(RechargeAccount::DATE_FORMAT)]])
            ->log('auto-renew-skipped');
    }

    /**
     * Whether the account already has an entry of this event for this expiry.
     */
    private function loggedFor(Account $account, string $event, string $expiry): bool
    {
        return Activity::query()
            ->whereMorphedTo('subject', $account)
            ->where('event', $event)
            ->where('attribute_changes->attributes->expiry', $expiry)
            ->exists();
    }

    /**
     * Someone else renewing meanwhile is expected and skipped; anything else is reported and the run goes on.
     */
    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (ExpiryChangedMeanwhile|ModelNotFoundException) {
            return;
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
