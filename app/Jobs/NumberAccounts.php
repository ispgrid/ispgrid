<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\User;
use App\Support\AccountNumbers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Gives every customer without an account ID one, oldest first, when the operator first saves a start number.
 * Deleted customers are left out. Each customer is numbered in its own short transaction, so staff saving customers
 * meanwhile only wait for one row; running it twice numbers nobody twice. One activity entry says how many.
 */
class NumberAccounts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public ?int $userId = null) {}

    public function handle(): void
    {
        $count = 0;

        Account::query()->whereNull('account_number')->orderBy('id')->select('id')
            ->chunkById(500, function ($accounts) use (&$count): void {
                foreach ($accounts as $row) {
                    $count += DB::transaction(function () use ($row): int {
                        $account = Account::query()->lockForUpdate()->find($row->id);

                        if ($account === null || $account->account_number !== null) {
                            return 0;
                        }

                        AccountNumbers::assign($account);

                        return $account->account_number === null ? 0 : 1;
                    }, attempts: 3);
                }
            });

        if ($count > 0) {
            activity()
                ->causedBy($this->userId === null ? null : User::query()->find($this->userId))
                ->event('account-numbers-assigned')
                ->withChanges(['attributes' => ['count' => $count]])
                ->log('account-numbers-assigned');
        }
    }
}
