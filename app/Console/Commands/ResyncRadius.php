<?php

namespace App\Console\Commands;

use App\Actions\Radius\SyncAccountToRadius;
use App\Actions\Radius\SyncPlanToRadius;
use App\Models\Account;
use App\Models\Plan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rebuilds the FreeRADIUS rows from the app tables: after a manual edit, a bug or a database restore. Safe to
 * run at any time: each plan and each customer is rewritten in its own transaction, the way a save does it.
 */
#[Signature('radius:resync
    {username? : Rebuild this customer only}
    {--all : Rebuild every plan and every customer}')]
#[Description('Rebuild the FreeRADIUS rows of customers and plans from the app tables')]
class ResyncRadius extends Command
{
    public function handle(SyncAccountToRadius $accounts, SyncPlanToRadius $plans): int
    {
        $username = $this->argument('username');
        $all = (bool) $this->option('all');

        if ($all === ($username !== null)) {
            $this->error('Give either a username or --all.');

            return self::FAILURE;
        }

        if ($username !== null) {
            $id = Account::withTrashed()->where('username', $username)->value('id');

            if ($id === null) {
                $this->error("No customer is called {$username}.");

                return self::FAILURE;
            }

            $this->syncAccount($accounts, (int) $id);
            $this->info("Rebuilt the rows of {$username}.");

            return self::SUCCESS;
        }

        $planCount = 0;

        foreach (Plan::withTrashed()->orderBy('id')->pluck('id') as $id) {
            DB::transaction(fn () => $plans->handle(Plan::withTrashed()->lockForUpdate()->findOrFail($id)), attempts: 3);
            $planCount++;
        }

        $accountCount = 0;

        Account::withTrashed()->select('id')->chunkById(500, function (Collection $chunk) use ($accounts, &$accountCount): void {
            foreach ($chunk as $account) {
                $this->syncAccount($accounts, $account->id);
                $accountCount++;
            }
        });

        $this->info('Rebuilt the rows of '.$planCount.' '.Str::plural('plan', $planCount)
            .' and '.$accountCount.' '.Str::plural('customer', $accountCount).'.');

        return self::SUCCESS;
    }

    private function syncAccount(SyncAccountToRadius $sync, int $id): void
    {
        DB::transaction(fn () => $sync->handle(Account::withTrashed()->lockForUpdate()->findOrFail($id)), attempts: 3);
    }
}
