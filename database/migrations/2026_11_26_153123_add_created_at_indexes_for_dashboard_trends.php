<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard's trend lines count recharges and new customers per day, so both tables need their creation date
 * indexed to stay fast on a big box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recharges', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('recharges', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
