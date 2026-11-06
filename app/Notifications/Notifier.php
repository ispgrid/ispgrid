<?php

namespace App\Notifications;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Models\Recharge;
use App\Notifications\Contracts\PaymentTrigger;
use App\Notifications\Contracts\Trigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Decides who gets which message. It writes one notification_log row per message (the unique once-key stops a
 * second one) and queues SendNotification for it. Providers never see any of this.
 */
final class Notifier
{
    public function __construct(private NotificationRegistry $registry, private Placeholders $placeholders) {}

    /**
     * Rows for every enabled scheduled rule's due customers. Outside the sending hours nothing is made; the next
     * run inside them picks the same customers up, since the triggers work by calendar day.
     */
    public function dispatchScheduled(CarbonImmutable $now): int
    {
        if (! self::withinHours($now)) {
            return 0;
        }

        $queued = 0;
        $enabledProviders = NotificationProvider::enabledKeys();

        foreach (NotificationRule::query()->where('enabled', true)->orderBy('id')->get() as $rule) {
            $trigger = $this->registry->trigger($rule->trigger);

            if ($trigger === null || ! $trigger::scheduled() || ! in_array($rule->provider, $enabledProviders, true)) {
                continue;
            }

            $this->filtered($trigger->due($rule->trigger_settings ?? [], $now), $rule)
                ->where('notifications_off', false)
                ->with('plan')
                ->chunkById(200, function (Collection $accounts) use ($rule, $trigger, $now, &$queued): void {
                    foreach ($accounts as $account) {
                        if ($this->queue($rule, $trigger, $account, null, $now)?->status === NotificationStatus::Queued) {
                            $queued++;
                        }
                    }
                });
        }

        return $queued;
    }

    /**
     * An event happened (a recharge, a new customer): message the customer for every enabled rule on it.
     * Called inside the caller's transaction; the job is queued after the commit. A secret (a new password) is held
     * encrypted on each message, apart from its values, until that message is sent.
     */
    public function fire(string $triggerKey, Account $account, ?Recharge $recharge = null, ?PaymentNotice $payment = null, ?string $secret = null): void
    {
        $trigger = $this->registry->trigger($triggerKey);

        if ($trigger === null) {
            return;
        }

        $now = CarbonImmutable::now();
        $account->loadMissing('plan');

        foreach ($this->rulesFor($triggerKey, $account) as $rule) {
            $this->queue($rule, $trigger, $account, $recharge, $now, $payment, $secret);
        }
    }

    /**
     * The enabled rules on this trigger, with an enabled provider, whose filters take this customer.
     *
     * @return Collection<int, NotificationRule>
     */
    public function rulesFor(string $triggerKey, Account $account): Collection
    {
        $enabledProviders = NotificationProvider::enabledKeys();

        return NotificationRule::query()->where('enabled', true)->where('trigger', $triggerKey)->orderBy('id')->get()
            ->filter(fn (NotificationRule $rule): bool => in_array($rule->provider, $enabledProviders, true)
                && $this->filtered(Account::query()->whereKey($account->id), $rule)->exists())
            ->values();
    }

    /**
     * The saved rule to one number, with a real customer's values when there is one. Logged without a once-key.
     * A password is never a real one: the test sends NotificationLog::SAMPLE_SECRET.
     */
    public function sendTest(NotificationRule $rule, string $to): NotificationLog
    {
        $provider = $this->registry->providerClass($rule->provider);
        $result = $this->placeholders->values($rule->values ?? [], self::sample(), null, CarbonImmutable::now(),
            new PaymentNotice('test', '590.00', 'RCPT-1', 'INV-1', __('Online (Paytm)'), '0.00'));
        $hasSecret = in_array('selfcare_password', $rule->values ?? [], true);

        $log = NotificationLog::query()->create([
            'notification_rule_id' => $rule->id,
            'provider' => $rule->provider,
            'channel' => $provider::channel(),
            'to' => $to,
            'template' => $rule->template ?? [],
            'values' => $result['values'],
            'values_trimmed' => $result['trimmed'],
            'preview' => Placeholders::preview($rule->approved_text, $result['values']),
            'status' => NotificationStatus::Queued,
            'user_id' => auth()->id(),
            'secret' => $hasSecret ? NotificationLog::SAMPLE_SECRET : null,
            'has_secret' => $hasSecret,
        ]);

        SendNotification::dispatchSync($log->id);
        $log->refresh();

        // A test runs once, here: a retryable failure is not tried again, so it is final (and holds no password).
        if ($log->status === NotificationStatus::Queued) {
            $log->update(['status' => NotificationStatus::Failed, 'secret' => null]);
        }

        return $log;
    }

    /**
     * Sends a failed message again, with the template its rule has now (the usual fix is a corrected template ID).
     */
    public function retry(NotificationLog $log): void
    {
        if (! $log->canRetry()) {
            return;
        }

        $rule = $log->rule;
        $template = $rule !== null && ! $rule->trashed() && $rule->provider === $log->provider ? $rule->template : $log->template;

        $log->update(['status' => NotificationStatus::Queued, 'attempts' => 0, 'error' => null, 'template' => $template, 'user_id' => auth()->id()]);

        SendNotification::dispatch($log->id);
    }

    /**
     * Date reminders go out only between notify_hours_from (inclusive) and notify_hours_to (exclusive).
     */
    public static function withinHours(CarbonImmutable $now): bool
    {
        $time = $now->format('H:i');

        return $time >= AppSetting::valueOf('notify_hours_from', '09:00') && $time < AppSetting::valueOf('notify_hours_to', '21:00');
    }

    /**
     * The customer previews and Send test take values from.
     */
    public static function sample(): ?Account
    {
        return Account::query()->with('plan')->whereNotNull('expiry')->whereNotNull('plan_id')->orderBy('id')->first();
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    private function filtered(Builder $query, NotificationRule $rule): Builder
    {
        $plans = $rule->filters['plans'] ?? [];
        $serviceTypes = $rule->filters['service_types'] ?? [];
        $statuses = $rule->filters['statuses'] ?? [];

        return $query
            ->when($plans !== [], fn (Builder $q) => $q->whereIn('plan_id', $plans))
            ->when($serviceTypes !== [], fn (Builder $q) => $q->whereIn('service_type', $serviceTypes))
            ->when($statuses !== [], fn (Builder $q) => $q->whereIn('status_id', $statuses));
    }

    private function queue(NotificationRule $rule, Trigger $trigger, Account $account, ?Recharge $recharge, CarbonImmutable $now, ?PaymentNotice $payment = null, ?string $secret = null): ?NotificationLog
    {
        $provider = $this->registry->providerClass($rule->provider);
        $key = $trigger instanceof PaymentTrigger && $payment !== null
            ? $trigger->paymentOnceKey($rule->id, $account, $payment)
            : $trigger->onceKey($rule->id, $account, $recharge);

        if ($provider === null || NotificationLog::query()->where('once_key', $key)->exists()) {
            return null;
        }

        $raw = PhoneNumber::rawFor($account, $provider::channel());
        $to = PhoneNumber::fromSettings($raw);
        $result = $this->placeholders->values($rule->values ?? [], $account, $recharge, $now, $payment);
        [$status, $error] = match (true) {
            trim((string) $raw) === '' => [NotificationStatus::Skipped, __('No mobile number.')],
            $to === null => [NotificationStatus::Skipped, __('Invalid number: :number', ['number' => $raw])],
            default => [NotificationStatus::Queued, null],
        };

        try {
            $log = NotificationLog::query()->create([
                'notification_rule_id' => $rule->id,
                'account_id' => $account->id,
                'provider' => $rule->provider,
                'channel' => $provider::channel(),
                'to' => $to ?? mb_substr((string) $raw, 0, 30),
                'template' => $rule->template ?? [],
                'values' => $result['values'],
                'values_trimmed' => $result['trimmed'],
                'preview' => Placeholders::preview($rule->approved_text, $result['values']),
                'status' => $status,
                'error' => $error,
                'once_key' => $key,
                // A message that is not going out (no number) never holds the password.
                'secret' => $secret !== null && $status === NotificationStatus::Queued ? $secret : null,
                'has_secret' => $secret !== null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // another run made it a moment ago
        }

        if ($status === NotificationStatus::Queued) {
            SendNotification::dispatch($log->id)->afterCommit();
        }

        return $log;
    }
}
