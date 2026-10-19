<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A range is IPoE (a subnet on a router port) or PPPoE (routed: every address usable, or a first-to-last
     * range). Existing ranges were all built for IPoE.
     */
    public function up(): void
    {
        Schema::table('ip_ranges', function (Blueprint $table) {
            $table->string('kind', 10)->default('ipoe')->after('name');
            $table->boolean('leave_out_edges')->default(false)->after('network');
        });
    }

    public function down(): void
    {
        Schema::table('ip_ranges', function (Blueprint $table) {
            $table->dropColumn(['kind', 'leave_out_edges']);
        });
    }
};
