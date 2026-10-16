<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Models\Account;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Notifications\NotificationRegistry;
use App\Notifications\Redactor;
use App\Notifications\SendResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one notification_log row through its provider. A retryable failure is tried again after the wait the
 * provider asked for, else after 1, 5, 15 and 60 minutes; the fifth failure is final. Any error text is stored
 * with the provider's secrets taken out.
 */
class SendNotification implements ShouldQueue
{
    use Queueable;

    private const BACKOFF = [60, 300, 900, 3600];

    public int $tries = 5;

    public int $timeout = 60;

    /**
     * On its own queue, so a slow provider never holds up Disconnect-Requests on the default one.
     */
    public function __construct(public int $logId)
    {
        $this->onQueue('notifications');
    }

    public function handle(NotificationRegistry $registry): void
    {
        $log = NotificationLog::query()->find($this->logId);

        if ($log === null || $log->status !== NotificationStatus::Queued) {
            return;
        }

        // A customer message (not a test) stops when its rule is switched off or deleted, even mid-retry.
        if ($log->once_key !== null && ($log->rule === null || $log->rule->trashed() || ! $log->rule->enabled)) {
            $log->update(['status' => NotificationStatus::Failed, 'error' => __('The rule is switched off or deleted.')]);

            return;
        }

        // Checked again at send time: the customer may have been deleted, or switched reminders off, since it was queued.
        if ($log->once_key !== null && $log->account_id !== null) {
            $account = Account::withTrashed()->find($log->account_id);
            $trigger = $registry->trigger($log->rule->trigger);

            if ($account === null || $account->trashed()) {
                $log->update(['status' => NotificationStatus::Failed, 'error' => __('The customer was deleted.')]);

                return;
            }

            if ($account->notifications_off && $trigger !== null && $trigger::scheduled()) {
                $log->update(['status' => NotificationStatus::Failed, 'error' => __('The customer has reminders switched off.')]);

                return;
            }
        }

        $config = NotificationProvider::query()->where('provider', $log->provider)->first();
        $provider = $config?->enabled ? $registry->makeProvider($log->provider, $config->settings ?? []) : null;

        if ($provider === null) {
            $log->update(['status' => NotificationStatus::Failed, 'error' => __('The provider is switched off.')]);

            return;
        }

        $log->increment('attempts');

        try {
            $result = $provider->send($log->toMessage());
        } catch (Throwable $exception) {
            $result = SendResult::failed($exception->getMessage(), retryable: true);
        }

        if ($result->sent) {
            $log->update(['status' => NotificationStatus::Sent, 'provider_message_id' => $result->providerMessageId, 'sent_at' => now(), 'error' => null]);

            return;
        }

        $reason = mb_substr(Redactor::scrub($result->reason, $registry->secretValues($log->provider, $config->settings ?? [])), 0, 500);

        if ($result->retryable && $this->attempts() < $this->tries) {
            $log->update(['error' => $reason]);
            $this->release($result->retryAfterSeconds ?? self::BACKOFF[min($this->attempts(), count(self::BACKOFF)) - 1]);

            return;
        }

        $log->update(['status' => NotificationStatus::Failed, 'error' => $reason]);
    }

    /**
     * The job died outside the send (a timeout, a database error, a key that no longer decrypts): the row must not
     * stay Queued, or it never gets a Retry button.
     */
    public function failed(Throwable $exception): void
    {
        $log = NotificationLog::query()->find($this->logId);

        if ($log === null || $log->status !== NotificationStatus::Queued) {
            return;
        }

        try {
            $secrets = app(NotificationRegistry::class)->secretValues($log->provider, NotificationProvider::settingsFor($log->provider));
        } catch (Throwable) {
            $secrets = [];
        }

        $log->update(['status' => NotificationStatus::Failed, 'error' => mb_substr(Redactor::scrub($exception->getMessage(), $secrets), 0, 500)]);
    }
}
