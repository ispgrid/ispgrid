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
 * meanwhile only wait for one row; running it twice numbers nobody twice. One job numbers one batch and queues the
 * next, so the single queue worker sends the disconnects and messages queued meanwhile between batches instead of
 * after the whole customer base. The last batch writes one activity entry saying how many.
 */
class NumberAccounts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** One batch: about 8 queries a customer. */
    public int $timeout = 120;

    /**
     * @param  int  $numbered  customers numbered by the batches before this one
     * @param  int  $afterId  the last customer the batch before looked at (a customer that cannot be numbered is passed by)
     */
    public function __construct(public ?int $userId = null, public int $numbered = 0, public int $afterId = 0, public int $batch = 200) {}

    public function handle(): void
    {
        $ids = Account::query()->whereNull('account_number')->where('id', '>', $this->afterId)
            ->orderBy('id')->limit($this->batch)->pluck('id');
        $numbered = $this->numbered;

        foreach ($ids as $id) {
            $numbered += DB::transaction(function () use ($id): int {
                $account = Account::query()->lockForUpdate()->whereKey($id)->first();

                if ($account === null || $account->account_number !== null) {
                    return 0;
                }

                AccountNumbers::assign($account);

                return $account->account_number === null ? 0 : 1;
            }, attempts: 3);
        }

        $last = (int) $ids->last();

        if ($ids->count() === $this->batch && Account::query()->whereNull('account_number')->where('id', '>', $last)->exists()) {
            self::dispatch($this->userId, $numbered, $last, $this->batch);

            return;
        }

        if ($numbered > 0) {
            activity()
                ->causedBy($this->userId === null ? null : User::query()->find($this->userId))
                ->event('account-numbers-assigned')
                ->withChanges(['attributes' => ['count' => $numbered]])
                ->log('account-numbers-assigned');
        }
    }
}
