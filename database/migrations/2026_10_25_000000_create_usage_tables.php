<?php

use App\Support\UsageBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usage per customer per day, in its own database (config ispgrid.usage_database, ispgrid_usage) on the same server:
 * usage_daily (one row per customer per day) and usage_period (the running total since the last recharge, which the
 * total limits read). Two triggers on radacct book every change in a session's counters as it is written, so
 * FreeRADIUS writes radacct exactly as before. The existing sessions are booked once here, so limits carry on.
 * down() removes the triggers only: the usage history stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = $this->database();

        DB::statement("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        if (! Schema::hasTable("{$db}.usage_daily")) {
            Schema::create("{$db}.usage_daily", function (Blueprint $table) {
                $table->string('username', 64);
                $table->date('day');
                $table->unsignedBigInteger('bytes_in')->default(0);
                $table->unsignedBigInteger('bytes_out')->default(0);
                $table->unsignedBigInteger('seconds')->default(0);
                $table->primary(['username', 'day']);
                $table->index('day');
            });
        }

        if (! Schema::hasTable("{$db}.usage_period")) {
            Schema::create("{$db}.usage_period", function (Blueprint $table) {
                $table->string('username', 64)->primary();
                $table->dateTime('since')->nullable();
                $table->unsignedBigInteger('bytes')->default(0);
                $table->unsignedBigInteger('seconds')->default(0);
            });
        }

        UsageBackfill::run($db);

        DB::unprepared('DROP TRIGGER IF EXISTS radacct_usage_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS radacct_usage_update');
        // A trigger body cannot be a prepared statement; the database name in it was checked by database().
        DB::connection()->getPdo()->exec($this->trigger('radacct_usage_insert', 'INSERT', $db, '0'));
        DB::connection()->getPdo()->exec($this->trigger('radacct_usage_update', 'UPDATE', $db, 'OLD'));
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS radacct_usage_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS radacct_usage_update');
    }

    private function database(): string
    {
        $db = (string) config('ispgrid.usage_database');

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $db) !== 1) {
            throw new InvalidArgumentException("The usage database name {$db} is not allowed.");
        }

        return $db;
    }

    /**
     * Books what a session's counters grew by into today's row and the period total. A counter that went down (the
     * NAS restarted its count) counts from zero. A failure here never stops FreeRADIUS writing radacct.
     *
     * @param  'OLD'|'0'  $old  the previous counters: OLD on update, nothing on insert
     */
    private function trigger(string $name, string $event, string $db, string $old): string
    {
        $previous = fn (string $column): string => $old === 'OLD' ? "COALESCE(OLD.{$column}, 0)" : '0';
        $grown = fn (string $column): string => "IF(COALESCE(NEW.{$column}, 0) >= {$previous($column)}, COALESCE(NEW.{$column}, 0) - {$previous($column)}, COALESCE(NEW.{$column}, 0))";

        return <<<SQL
            CREATE TRIGGER {$name} AFTER {$event} ON radacct FOR EACH ROW
            BEGIN
                DECLARE grown_in BIGINT DEFAULT {$grown('acctinputoctets')};
                DECLARE grown_out BIGINT DEFAULT {$grown('acctoutputoctets')};
                DECLARE grown_time BIGINT DEFAULT {$grown('acctsessiontime')};
                DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;

                IF grown_in + grown_out + grown_time > 0 AND NEW.username IS NOT NULL THEN
                    INSERT INTO `{$db}`.usage_daily (username, day, bytes_in, bytes_out, seconds)
                        VALUES (NEW.username, CURDATE(), grown_in, grown_out, grown_time)
                        ON DUPLICATE KEY UPDATE bytes_in = bytes_in + VALUES(bytes_in), bytes_out = bytes_out + VALUES(bytes_out), seconds = seconds + VALUES(seconds);
                    INSERT INTO `{$db}`.usage_period (username, since, bytes, seconds)
                        VALUES (NEW.username, NOW(), grown_in + grown_out, grown_time)
                        ON DUPLICATE KEY UPDATE bytes = bytes + VALUES(bytes), seconds = seconds + VALUES(seconds);
                END IF;
            END
            SQL;
    }
};
