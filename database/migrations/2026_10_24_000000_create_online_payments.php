<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick pay: each payment gateway's saved settings (keys encrypted with APP_KEY), every attempt a customer makes on
 * the pay page, and a gateway column on payment_methods marking the fixed "Online (gateway)" methods that staff never
 * pick. Quick pay stays off (billing.quick_pay is not set). FreeRADIUS reads none of this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 40)->unique();
            $table->boolean('enabled')->default(false);
            $table->text('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('account_id')->constrained();
            $table->string('gateway', 40);
            $table->decimal('amount', 12, 2);
            $table->decimal('dues_amount', 12, 2);
            $table->decimal('renewal_amount', 12, 2)->default(0);
            $table->char('currency', 3);
            $table->string('renewal_kind', 10)->nullable();
            $table->foreignId('renewal_plan_id')->nullable()->constrained('plans');
            $table->string('status', 20)->index();
            $table->string('gateway_txn_id', 100)->nullable();
            $table->string('gateway_status', 40)->nullable();
            $table->json('gateway_response')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'created_at']);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('gateway', 40)->nullable()->unique()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique(['gateway']);
            $table->dropColumn('gateway');
        });

        Schema::dropIfExists('online_payments');
        Schema::dropIfExists('payment_gateways');
    }
};
