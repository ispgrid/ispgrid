<?php

namespace App\Actions\Customers;

use App\Actions\Radius\ResetDevices;
use App\Actions\Radius\SyncAccountToRadius;
use App\Actions\Radius\UnlockAccount;
use App\Models\Account;
use App\Models\CustomerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Soft delete. Public add-on API. The IP goes back to the inventory and the pool is dropped first; then the
 * FreeRADIUS rows, the ipoes row, the bound devices and the counters go, so the customer can no longer log in and
 * the device can be used by another customer. One "Deleted" entry records the IP, the pool and every device.
 * radacct is kept, so the caller disconnects an open session after the commit
 * (DisconnectAccount::dispatchIfOnline).
 */
final class DeleteAccount
{
    public function __construct(private SyncAccountToRadius $sync, private ResetDevices $reset, private UnlockAccount $unlock) {}

    /**
     * @throws ModelNotFoundException when the customer was deleted meanwhile
     */
    public function handle(int $accountId): void
    {
        DB::transaction(function () use ($accountId): void {
            $account = Account::query()->with(['ip', 'pool'])->lockForUpdate()->findOrFail($accountId);
            $previousIpId = $account->ip_id;
            $devices = DB::table('account_macs')->where('username', $account->username)->orderBy('learned_at')->get()
                ->map(fn (object $row): array => [
                    'mac' => implode(':', str_split($row->mac_norm, 2)),
                    'bound' => (string) $row->learned_at,
                    'last_seen' => $row->last_seen === null ? null : (string) $row->last_seen,
                ])
                ->all();
            $removed = array_filter([
                'ip_address' => $account->ip?->address,
                'pool' => $account->pool?->name,
                'devices' => implode(', ', array_column($devices, 'mac')),
            ], fn (?string $value): bool => $value !== null && $value !== '');

            // Every service ends with the customer (a restore does not bring them back); the delete below frees the
            // static IP and its own entry records it.
            CustomerService::query()->where('account_id', $account->id)->whereNull('ended_at')
                ->update(['ended_at' => now(), 'ended_by' => auth()->id(), 'end_reason' => 'Customer deleted']);

            $account->disableLogging();
            $account->update(['ip_id' => null, 'pool_id' => null]);
            $account->delete();
            $this->sync->handle($account, $previousIpId);
            $this->reset->handle($account->username);
            $this->unlock->handle($account->username);

            activity()
                ->performedOn($account)
                ->event('deleted')
                ->withChanges(['old' => $removed, 'attributes' => []])
                ->withProperties(['devices' => $devices])
                ->log('deleted');
        }, attempts: 3);
    }
}
