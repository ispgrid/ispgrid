<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payments round two: the screenshot kept with a manual payment (a path on the billing disk), where an online
 * attempt started (quick pay or Selfcare), and the gateway's own order or session id, which settling never overwrites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('proof_path', 255)->nullable()->after('note');
        });

        Schema::table('online_payments', function (Blueprint $table) {
            $table->string('origin', 20)->default('quick_pay')->after('gateway');
            $table->string('gateway_order_id', 100)->nullable()->after('gateway_txn_id');
            $table->index(['gateway', 'gateway_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            $table->dropIndex(['gateway', 'gateway_order_id']);
            $table->dropColumn(['origin', 'gateway_order_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('proof_path');
        });
    }
};
