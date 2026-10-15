<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the FreeRADIUS logs bounded: login attempts (radpostauth) older than the App settings period, and
 * reject counters untouched for a day. FreeRADIUS only reads counters newer than Reject-Packet-Interval
 * minutes, so removing day-old ones never changes a login decision.
 * Ages use the database clock (NOW()), the same clock FreeRADIUS writes them with.
 */
#[Signature('radius:prune
    {--chunk=5000 : Login log rows removed per statement}')]
#[Description('Remove old login log rows and stale reject counters')]
class PruneRadiusLogs extends Command
{
    public const DEFAULT_LOGIN_LOG_DAYS = 90;

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');

        if ($chunk < 1) {
            $this->error('--chunk must be at least 1.');

            return self::FAILURE;
        }

        $loginLog = $this->pruneLoginLog($this->loginLogDays(), $chunk);

        $rejectCounters = DB::table('reject_counters')
            ->whereRaw('last_update < NOW() - INTERVAL 1 DAY')
            ->delete();

        $summary = "Login log: {$loginLog} rows removed. Reject counters: {$rejectCounters} rows removed.";
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
}
