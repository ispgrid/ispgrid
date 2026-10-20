<?php

namespace App\Actions\Customers;

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Models\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Ends the service: status Terminated, no plan, auto renew off and a waiting advance renewal cancelled. The
 * expiry stays as a record. The caller disconnects the customer. They come back by a status change and a recharge.
 */
final class TerminateAccount
{
    public function __construct(private SyncAccountToRadius $sync) {}

    /**
     * @throws ModelNotFoundException when the customer was deleted meanwhile
     */
    public function handle(int $accountId, string $note): void
    {
        DB::transaction(function () use ($accountId, $note): void {
            $account = Account::query()->with('plan')->lockForUpdate()->findOrFail($accountId);
            $oldStatus = $account->status_id;
            $oldPlan = $account->plan?->name;

            CancelAdvanceRenewal::cancelWaiting($account);

            $account->disableLogging();
            $account->update(['status_id' => AccountStatus::Terminated, 'plan_id' => null, 'auto_renew' => false]);
            $this->sync->handle($account);

            activity()
                ->performedOn($account)
                ->event('terminated')
                ->withChanges([
                    'old' => ['status' => $oldStatus->label(), 'plan' => $oldPlan],
                    'attributes' => ['status' => AccountStatus::Terminated->label(), 'note' => $note],
                ])
                ->log('terminated');
        }, attempts: 3);
    }
}
