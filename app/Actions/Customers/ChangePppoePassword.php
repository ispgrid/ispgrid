<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * The customer changes their own PPPoE password in Selfcare: saved and written to FreeRADIUS through the same sync
 * staff use, logged with the customer as the one who did it (never the password), and then the customer is
 * disconnected so the new password applies at once.
 */
final class ChangePppoePassword
{
    public function __construct(private SyncAccountToRadius $sync) {}

    public function handle(Account $account, string $password): void
    {
        $username = DB::transaction(function () use ($account, $password): string {
            $row = Account::query()->lockForUpdate()->findOrFail($account->id);
            $row->disableLogging();
            $row->update(['password' => $password]);
            $this->sync->handle($row);

            activity()->performedOn($row)->causedBy($account)->event('pppoe-password-changed')->log('pppoe-password-changed');

            return (string) $row->username;
        }, attempts: 3);

        DisconnectAccount::dispatchIfOnline($username);
    }
}
