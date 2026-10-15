<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The nightly cleanup (radius:prune) and the Login log date filter look rows up by date alone;
     * the (nasipaddress, authdate) index can't serve that.
     */
    public function up(): void
    {
        Schema::table('radpostauth', function (Blueprint $table) {
            $table->index('authdate', 'idx_radpostauth_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('radpostauth', function (Blueprint $table) {
            $table->dropIndex('idx_radpostauth_date');
        });
    }
};
