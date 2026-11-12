<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\DisconnectReason;
use App\Enums\NotificationStatus;
use App\Enums\ServiceType;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\NotificationLog;
use App\Notifications\Notifier;
use App\Notifications\Triggers\PppoePassword;
use App\Support\PasswordPattern;
use Illuminate\Support\Facades\DB;

/**
 * Staff give a PPPoE customer a new password: from the password pattern, or 10 random characters that are easy to
 * read out (also when the pattern would give the password they have now). Saved and written to FreeRADIUS in one
 * transaction, logged without the password, optionally sent by the "PPPoE password" message rules, and then the
 * customer is disconnected so the new password applies at once.
 */
final class ResetPppoePassword
{
    public function __construct(private SyncAccountToRadius $sync, private Notifier $notifier) {}

    /**
     * @return string the new password, to show once
     */
    public function handle(Account $account): string
    {
        return $this->reset($account, false)['password'];
    }

    /**
     * Resets and sends the new password by every enabled "PPPoE password" rule.
     *
     * @return array{password: string, queued: bool} queued: false when no message is going out (no valid number),
     *                                               so staff must give the password themselves
     */
    public function handleAndSend(Account $account): array
    {
        return $this->reset($account, true);
    }

    /**
     * Whether an enabled "PPPoE password" rule with an enabled provider takes this customer.
     */
    public function canSend(Account $account): bool
    {
        return $this->notifier->rulesFor(PppoePassword::key(), $account)->isNotEmpty();
    }

    /**
     * @return array{password: string, queued: bool}
     */
    private function reset(Account $account, bool $send): array
    {
        $result = DB::transaction(function () use ($account, $send): array {
            $row = Account::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($row->service_type === ServiceType::Ppp, 404);
            $password = PasswordPattern::make($row->fullname, $row->mobile);
            $password = $password === null || $password === $row->password ? SetSelfcarePassword::random() : $password;

            $row->disableLogging();
            $row->update(['password' => $password]);
            $this->sync->handle($row);

            activity()->performedOn($row)->event('pppoe-password-reset')->log('pppoe-password-reset');
            $queued = false;

            if ($send) {
                $lastLog = (int) NotificationLog::query()->max('id');
                $this->notifier->fire(PppoePassword::key(), $row, secret: $password);
                $queued = NotificationLog::query()->where('id', '>', $lastLog)->where('account_id', $row->id)
                    ->where('status', NotificationStatus::Queued)->exists();
            }

            return ['username' => (string) $row->username, 'password' => $password, 'queued' => $queued];
        }, attempts: 3);

        DisconnectAccount::dispatchIfOnline($result['username'], DisconnectReason::PppoePasswordReset);

        return ['password' => $result['password'], 'queued' => $result['queued']];
    }
}
