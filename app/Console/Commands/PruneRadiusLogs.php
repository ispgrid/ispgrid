<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Support\SessionKeepers;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the FreeRADIUS logs bounded: login attempts (radpostauth) and closed sessions (radacct) older than the App
 * settings periods, reject counters untouched for a day, and usage per day older than 400 days (or the session
 * history, when that is longer). Limits read the usage database, so old sessions are never kept for them. FreeRADIUS only reads counters newer than Reject-Packet-Interval
 * minutes, so removing day-old ones never changes a login decision.
 * Ages use the database clock (NOW()), not PHP's: FreeRADIUS writes the times in the server's local time,
 * which is the database's time on the same box, whatever the app timezone is.
 * With session keepers (add-ons that archive sessions), a session is deleted only when every keeper already has
 * it; a keeper that fails stops the session part for that night.
 */
#[Signature('radius:prune
    {--chunk=5000 : Rows removed per statement}')]
#[Description('Remove old login log rows, old closed sessions and stale reject counters')]
class PruneRadiusLogs extends Command
{
    public const DEFAULT_LOGIN_LOG_DAYS = 90;

    public const DEFAULT_SESSION_HISTORY_DAYS = 365;

    /**
     * Days of usage per day kept at least (13 months: this month last year stays comparable).
     */
    public const USAGE_DAYS = 400;

    private SessionKeepers $keepers;

    private int $sessionsRemoved = 0;

    public function handle(SessionKeepers $keepers): int
    {
        $this->keepers = $keepers;
        $chunk = (int) $this->option('chunk');

        if ($chunk < 1) {
            $this->error('--chunk must be at least 1.');

            return self::FAILURE;
        }

        $loginLog = $this->pruneLoginLog($this->loginLogDays(), $chunk);

        try {
            $sessions = $this->pruneSessions($this->sessionHistoryDays(), $chunk);
            $stopped = null;
        } catch (Throwable $exception) {
            $sessions = $this->sessionsRemoved;
            $stopped = "Session cleanup stopped: {$exception->getMessage()}";
        }

        $rejectCounters = DB::table('reject_counters')
            ->whereRaw('last_update < NOW() - INTERVAL 1 DAY')
            ->delete();

        $usageDays = $this->pruneUsageDays(max(self::USAGE_DAYS, $this->sessionHistoryDays()), $chunk);

        $summary = "Login log: {$loginLog} rows removed. Reject counters: {$rejectCounters} rows removed. Sessions: {$sessions} rows removed. Usage days: {$usageDays} rows removed.";
        Log::info($summary);
        $this->info($summary);

        if ($stopped !== null) {
            Log::error($stopped);
            $this->error($stopped);

            return self::FAILURE;
        }

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
                ->orderBy('radacctid')
                ->limit($chunk)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $afterId = (int) $batch->last()->radacctid;
            $ids = $this->deletable(array_values(array_map(fn (mixed $id): string => (string) $id, $batch->pluck('acctuniqueid')->all())));

            if ($ids !== []) {
                $removed += DB::table('radacct')->whereIn('acctuniqueid', $ids)->delete();
                $this->sessionsRemoved = $removed;
            }
        } while ($batch->count() === $chunk);

        return $removed;
    }

    /**
     * Usage rows older than $days, in chunks along the day index.
     */
    private function pruneUsageDays(int $days, int $chunk): int
    {
        $removed = 0;
        $table = config('ispgrid.usage_database').'.usage_daily';

        do {
            $deleted = DB::table($table)->whereRaw('day < CURDATE() - INTERVAL ? DAY', [$days])->orderBy('day')->limit($chunk)->delete();
            $removed += $deleted;
        } while ($deleted === $chunk);

        return $removed;
    }

    /**
     * With keepers, only what every keeper already holds; ids a keeper adds on its own are ignored.
     *
     * @param  list<string>  $acctUniqueIds
     * @return list<string>
     */
    private function deletable(array $acctUniqueIds): array
    {
        foreach ($this->keepers->all() as $keeper) {
            $acctUniqueIds = array_values(array_intersect($acctUniqueIds, $keeper->kept($acctUniqueIds)));
        }

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
