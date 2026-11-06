<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick pay limits tries per visitor address (so a stranger who knows a username cannot use up the customer's
 * tries) and reuses an open attempt when Pay is pressed again: the gateway's start (a Paytm txnToken lasts 15
 * minutes) is kept, encrypted, with the attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            $table->string('client_ip', 45)->nullable()->after('status');
            $table->text('gateway_start')->nullable()->after('client_ip');
            $table->index(['account_id', 'client_ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'client_ip', 'created_at']);
            $table->dropColumn(['client_ip', 'gateway_start']);
        });
    }
};
