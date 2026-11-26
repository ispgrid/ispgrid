<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Models\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Changes a customer's status, rebuilds their FreeRADIUS rows and logs one "Status changed" entry with the old and
 * new status and the remark. Public add-on API. The caller disconnects an online customer when it returns true
 * (DisconnectAccount::dispatchIfOnline after the commit).
 */
final class SetAccountStatus
{
    public function __construct(private SyncAccountToRadius $sync) {}

    /**
     * @return bool whether FreeRADIUS now reads different rows
     *
     * @throws ModelNotFoundException when the customer was deleted meanwhile
     */
    public function handle(int $accountId, AccountStatus $status, string $remark): bool
    {
        return DB::transaction(function () use ($accountId, $status, $remark): bool {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            $old = $account->status_id;

            if ($old === $status) {
                return false;
            }

            $account->disableLogging();
            $account->update(['status_id' => $status]);
            $changed = $this->sync->handle($account);

            activity()
                ->performedOn($account)
                ->event('status-changed')
                ->withChanges([
                    'old' => ['status' => $old->label()],
                    'attributes' => ['status' => $account->status_id->label(), 'remark' => $remark],
                ])
                ->log('status-changed');

            return $changed;
        }, attempts: 3);
    }
}
