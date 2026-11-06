<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\DisconnectReason;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\UsageDay;
use App\Models\UsagePeriod;
use Illuminate\Support\Facades\DB;

/**
 * Gives a customer a new username. What FreeRADIUS reads moves with it in one transaction: the check, reply and group
 * rows (rewritten under the new name), the devices bound to it and the usage that limits count. The reject counters
 * start again. Sessions and the login log keep the name they really used (session records must, by law), so they
 * stay under the old one. An online customer is disconnected after the commit; their router must log in with the new
 * name from then on.
 */
final class RenameAccount
{
    public function __construct(private SyncAccountToRadius $sync) {}

    /**
     * @return bool whether a disconnect was sent
     */
    public function handle(Account $account, string $username): bool
    {
        $old = DB::transaction(function () use ($account, $username): string {
            $row = Account::query()->lockForUpdate()->findOrFail($account->id);
            $old = $row->username;

            foreach (['radcheck', 'radreply', 'radusergroup', 'reject_counters'] as $table) {
                DB::table($table)->where('username', $old)->delete();
            }

            DB::table('account_macs')->where('username', $old)->update(['username' => $username]);
            UsageDay::query()->where('username', $old)->update(['username' => $username]);
            UsagePeriod::query()->where('username', $old)->update(['username' => $username]);

            $row->disableLogging();
            $row->update(['username' => $username]);
            $this->sync->handle($row);

            activity()->performedOn($row)->event('username-changed')
                ->withChanges(['old' => ['username' => $old], 'attributes' => ['username' => $username]])
                ->log('username-changed');

            return $old;
        }, attempts: 3);

        return DisconnectAccount::dispatchIfOnline($old, DisconnectReason::UsernameChanged);
    }
}
