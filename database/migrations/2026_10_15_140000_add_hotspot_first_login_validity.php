<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hotspot validity from the first login: a plan's validity unit and first-login setting, the validity an
     * account has waiting for its first login (minutes; expiry stays null meanwhile), and a recharge that leaves
     * the validity waiting instead of setting an expiry.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('validity_unit', 5)->default('days')->after('validity');
            $table->boolean('hotspot_starts_at_first_login')->default(false)->after('validity_unit');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedInteger('hotspot_pending_validity')->nullable()->after('expiry');
            $table->dateTime('hotspot_first_login_at')->nullable()->after('hotspot_pending_validity');
        });

        Schema::table('recharges', function (Blueprint $table) {
            $table->dateTime('new_expiry')->nullable()->change();
            $table->unsignedInteger('new_pending_validity')->nullable()->after('new_expiry');
        });
    }

    /**
     * Fails while a recharge without an expiry exists: new_expiry cannot be required again then.
     */
    public function down(): void
    {
        Schema::table('recharges', function (Blueprint $table) {
            $table->dropColumn('new_pending_validity');
            $table->dateTime('new_expiry')->nullable(false)->change();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['hotspot_pending_validity', 'hotspot_first_login_at']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['validity_unit', 'hotspot_starts_at_first_login']);
        });
    }
};
