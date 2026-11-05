<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message that carries a password (Selfcare login) keeps it encrypted here only until it is sent or fails for
 * good; the values and preview hold ******** instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_log', function (Blueprint $table) {
            $table->text('secret')->nullable();
            $table->boolean('has_secret')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('notification_log', function (Blueprint $table) {
            $table->dropColumn(['secret', 'has_secret']);
        });
    }
};
