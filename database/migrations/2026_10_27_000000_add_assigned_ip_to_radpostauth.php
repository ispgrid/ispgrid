<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The IP an accepted login was given. FreeRADIUS's post-auth query stores the login's Acct-Session-Id (a MikroTik
 * sends it with a PPPoE login), and a trigger on radacct copies the address from the session's first accounting row
 * onto that login. down() removes the trigger and the columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radpostauth', function (Blueprint $table) {
            $table->string('acctsessionid', 64)->nullable();
            $table->string('framedipaddress', 15)->nullable();
            $table->index('framedipaddress', 'idx_radpostauth_ip');
        });

        DB::unprepared('DROP TRIGGER IF EXISTS radacct_login_ip');
        // A trigger body cannot be a prepared statement.
        DB::connection()->getPdo()->exec($this->trigger());
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS radacct_login_ip');

        Schema::table('radpostauth', function (Blueprint $table) {
            $table->dropIndex('idx_radpostauth_ip');
            $table->dropColumn(['acctsessionid', 'framedipaddress']);
        });
    }

    /**
     * Finds the accept the session belongs to: the same NAS, username and session id in the last 15 minutes (a lost
     * Start is replaced by the first interim, and a NAS that restarted its session ids cannot reach an old login), or,
     * for a login without a session id, the same NAS, username and MAC in the last 2 minutes. authdate = authdate
     * keeps the login time (the column updates itself). A failure here never stops FreeRADIUS writing radacct.
     */
    private function trigger(): string
    {
        $unclaimed = "nasipaddress = NEW.nasipaddress AND username = NEW.username AND reply = 'Access-Accept' AND framedipaddress IS NULL";

        return <<<SQL
            CREATE TRIGGER radacct_login_ip AFTER INSERT ON radacct FOR EACH ROW
            BEGIN
                DECLARE login_id BIGINT DEFAULT NULL;
                DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;

                IF COALESCE(NEW.framedipaddress, '') <> '' AND NEW.username IS NOT NULL THEN
                    IF COALESCE(NEW.acctsessionid, '') <> '' THEN
                        SET login_id = (SELECT id FROM radpostauth
                            WHERE {$unclaimed} AND acctsessionid = NEW.acctsessionid AND authdate >= NOW() - INTERVAL 15 MINUTE
                            ORDER BY id DESC LIMIT 1);
                    END IF;

                    IF login_id IS NULL THEN
                        SET login_id = (SELECT id FROM radpostauth
                            WHERE {$unclaimed} AND acctsessionid IS NULL AND mac = NEW.callingstationid AND authdate >= NOW() - INTERVAL 2 MINUTE
                            ORDER BY id DESC LIMIT 1);
                    END IF;

                    IF login_id IS NOT NULL THEN
                        UPDATE radpostauth SET framedipaddress = NEW.framedipaddress, authdate = authdate WHERE id = login_id;
                    END IF;
                END IF;
            END
            SQL;
    }
};
