<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The router list shows each router's last request; without this index that reads all of radpostauth.
     */
    public function up(): void
    {
        Schema::table('radpostauth', function (Blueprint $table) {
            $table->index(['nasipaddress', 'authdate'], 'idx_radpostauth_nas_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('radpostauth', function (Blueprint $table) {
            $table->dropIndex('idx_radpostauth_nas_date');
        });
    }
};
