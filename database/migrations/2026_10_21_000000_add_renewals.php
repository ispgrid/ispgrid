<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renewals: auto renew and an end for an override speed on the account, what kind each recharge was, and the
     * advance renewals waiting for the current period to end.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('usage_since');
            $table->dateTime('override_until')->nullable()->after('override_plan');
        });

        Schema::table('recharges', function (Blueprint $table) {
            $table->string('kind', 20)->default('recharge')->after('plan_id');
        });

        Schema::create('advance_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('plan_id')->constrained();
            $table->decimal('amount', 12, 2)->default(0);
            $table->dateTime('starts_at');
            $table->dateTime('expiry');
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('status', 20);
            $table->foreignId('recharge_id')->nullable()->constrained();
            $table->timestamps();

            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_renewals');

        Schema::table('recharges', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['auto_renew', 'override_until']);
        });
    }
};
