<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Books the sessions already in radacct into the usage database once, when it is created, so limits carry on as
 * FreeRADIUS counted them before: each customer's total since their last recharge (sessions started since then), and
 * usage per day by the day each session started (older days stay approximate). Running it again gives the same rows.
 */
final class UsageBackfill
{
    /**
     * How many days of history to book.
     */
    public const DAYS = 400;

    public static function run(string $database): void
    {
        DB::statement("INSERT INTO `{$database}`.usage_daily (username, day, bytes_in, bytes_out, seconds)
            SELECT username, DATE(acctstarttime), COALESCE(SUM(acctinputoctets), 0), COALESCE(SUM(acctoutputoctets), 0), COALESCE(SUM(acctsessiontime), 0)
            FROM radacct WHERE username IS NOT NULL AND acctstarttime >= CURDATE() - INTERVAL ".self::DAYS.' DAY GROUP BY username, DATE(acctstarttime)
            ON DUPLICATE KEY UPDATE bytes_in = VALUES(bytes_in), bytes_out = VALUES(bytes_out), seconds = VALUES(seconds)');

        DB::statement("INSERT INTO `{$database}`.usage_period (username, since, bytes, seconds)
            SELECT a.username, a.usage_since, COALESCE(SUM(r.acctinputoctets + r.acctoutputoctets), 0), COALESCE(SUM(r.acctsessiontime), 0)
            FROM accounts a LEFT JOIN radacct r ON r.username = a.username AND (a.usage_since IS NULL OR r.acctstarttime >= a.usage_since)
            WHERE a.deleted_at IS NULL GROUP BY a.username, a.usage_since
            ON DUPLICATE KEY UPDATE since = VALUES(since), bytes = VALUES(bytes), seconds = VALUES(seconds)");
    }
}
