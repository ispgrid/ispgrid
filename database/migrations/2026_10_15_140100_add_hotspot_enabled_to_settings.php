<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Hotspot switch (App settings), in the FreeRADIUS settings row so isp can refuse Hotspot page logins
     * while it is off. Off on a new install; an upgrade that finds Hotspot customers turns it on, so none of
     * them is hidden or locked out.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'hotspot_enabled')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('hotspot_enabled')->default(false)->after('reject_packet_interval');
            });
        }

        if (DB::table('accounts')->where('service_type', 'HOTSPOT')->whereNull('deleted_at')->exists()) {
            DB::table('settings')->update(['hotspot_enabled' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('hotspot_enabled');
        });
    }
};
