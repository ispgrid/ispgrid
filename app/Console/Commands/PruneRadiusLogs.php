<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the FreeRADIUS logs bounded: login attempts (radpostauth) and closed sessions (radacct) older than the App
 * settings periods, and reject counters untouched for a day. FreeRADIUS only reads counters newer than Reject-Packet-Interval
 * minutes, so removing day-old ones never changes a login decision.
 * Ages use the database clock (NOW()), not PHP's: FreeRADIUS writes the times in the server's local time,
 * which is the database's time on the same box, whatever the app timezone is.
 */
#[Signature('radius:prune
    {--chunk=5000 : Rows removed per statement}')]
#[Description('Remove old login log rows, old closed sessions and stale reject counters')]
class PruneRadiusLogs extends Command
{
    public const DEFAULT_LOGIN_LOG_DAYS = 90;

    public const DEFAULT_SESSION_HISTORY_DAYS = 365;

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');

        if ($chunk < 1) {
            $this->error('--chunk must be at least 1.');

            return self::FAILURE;
        }

        $loginLog = $this->pruneLoginLog($this->loginLogDays(), $chunk);

        $sessions = $this->pruneSessions($this->sessionHistoryDays(), $chunk);

        $rejectCounters = DB::table('reject_counters')
            ->whereRaw('last_update < NOW() - INTERVAL 1 DAY')
            ->delete();

        $summary = "Login log: {$loginLog} rows removed. Reject counters: {$rejectCounters} rows removed. Sessions: {$sessions} rows removed.";
        Log::info($summary);
        $this->info($summary);

        return self::SUCCESS;
    }

    /**
     * Small chunks, oldest first, so no single statement holds locks long while FreeRADIUS inserts.
     * Ordered by (authdate, id), the order of idx_radpostauth_date: each chunk reads only the rows it removes.
     */
    private function pruneLoginLog(int $days, int $chunk): int
    {
        $removed = 0;

        do {
            $deleted = DB::table('radpostauth')
                ->whereRaw('authdate < NOW() - INTERVAL ? DAY', [$days])
                ->orderBy('authdate')
                ->orderBy('id')
                ->limit($chunk)
                ->delete();
            $removed += $deleted;
        } while ($deleted === $chunk);

        return $removed;
    }

    /**
     * The App settings value, or 90 days when it is missing or not a whole number from 7 to 3650.
     */
    private function loginLogDays(): int
    {
        $days = filter_var(AppSetting::valueOf('login_log_days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 7, 'max_range' => 3650],
        ]);

        return $days === false ? self::DEFAULT_LOGIN_LOG_DAYS : $days;
    }

    /**
     * Closed sessions older than $days, oldest id first, skipping those a limit still counts: the customer's
     * sessions since usage_since (FreeRADIUS adds them up from Isp-Usage-Since; monthly and daily limits are
     * covered by the 31-day minimum). A deleted customer's sessions no longer count. Walks forward by radacctid,
     * so rows left in place are not read again.
     */
    private function pruneSessions(int $days, int $chunk): int
    {
        $removed = 0;
        $afterId = 0;

        do {
            $batch = DB::table('radacct')
                ->select(['radacctid', 'acctuniqueid'])
                ->where('radacctid', '>', $afterId)
                ->whereNotNull('acctstoptime')
                ->whereRaw('acctstoptime < NOW() - INTERVAL ? DAY', [$days])
                ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                    ->from('accounts')
                    ->whereColumn('accounts.username', 'radacct.username')
                    ->whereNull('accounts.deleted_at')
                    ->whereNotNull('accounts.usage_since')
                    ->whereColumn('radacct.acctstarttime', '>=', 'accounts.usage_since'))
                ->orderBy('radacctid')
                ->limit($chunk)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $afterId = (int) $batch->last()->radacctid;
            $ids = $this->deletable($batch->pluck('acctuniqueid')->all());

            if ($ids !== []) {
                $removed += DB::table('radacct')->whereIn('acctuniqueid', $ids)->delete();
            }
        } while ($batch->count() === $chunk);

        return $removed;
    }

    /**
     * @param  list<string>  $acctUniqueIds
     * @return list<string>
     */
    private function deletable(array $acctUniqueIds): array
    {
        return $acctUniqueIds;
    }

    /**
     * The App settings value, or 365 days when it is missing or not a whole number from 31 to 3650.
     */
    private function sessionHistoryDays(): int
    {
        $days = filter_var(AppSetting::valueOf('session_history_days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 31, 'max_range' => 3650],
        ]);

        return $days === false ? self::DEFAULT_SESSION_HISTORY_DAYS : $days;
    }
}
