<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\DisconnectReason;
use App\Enums\ServiceType;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Notifications\Notifier;
use App\Notifications\Triggers\PppoePassword;
use App\Support\PasswordPattern;
use Illuminate\Support\Facades\DB;

/**
 * Staff give a PPPoE customer a new password: from the password pattern, or 10 random characters that are easy to
 * read out. Saved and written to FreeRADIUS in one transaction, logged without the password, optionally sent by the
 * "PPPoE password" message rules, and then the customer is disconnected so the new password applies at once.
 */
final class ResetPppoePassword
{
    public function __construct(private SyncAccountToRadius $sync, private Notifier $notifier) {}

    /**
     * @return string the new password, to show once
     */
    public function handle(Account $account, bool $send = false): string
    {
        [$username, $password] = DB::transaction(function () use ($account, $send): array {
            $row = Account::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($row->service_type === ServiceType::Ppp, 404);
            $password = PasswordPattern::make($row->fullname, $row->mobile) ?? SetSelfcarePassword::random();

            $row->disableLogging();
            $row->update(['password' => $password]);
            $this->sync->handle($row);

            activity()->performedOn($row)->event('pppoe-password-reset')->log('pppoe-password-reset');

            if ($send) {
                $this->notifier->fire(PppoePassword::key(), $row, secret: $password);
            }

            return [(string) $row->username, $password];
        }, attempts: 3);

        DisconnectAccount::dispatchIfOnline($username, DisconnectReason::PppoePasswordReset);

        return $password;
    }

    /**
     * Whether an enabled "PPPoE password" rule with an enabled provider takes this customer.
     */
    public function canSend(Account $account): bool
    {
        return $this->notifier->rulesFor(PppoePassword::key(), $account)->isNotEmpty();
    }
}
