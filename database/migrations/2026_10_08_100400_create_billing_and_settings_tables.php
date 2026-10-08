<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recharges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('plan_id')->nullable()->constrained();
            $table->dateTime('old_expiry')->nullable();
            $table->dateTime('new_expiry');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamps();
        });

        Schema::create('ip_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('ip_range_id')->nullable()->constrained();
            $table->string('kind', 20);
            $table->string('network', 43)->unique();
            $table->string('gateway', 39)->nullable();
            $table->unsignedSmallInteger('vlan_id')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('radius_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('type', 20);
            $table->json('allowed_values')->nullable();
            $table->boolean('repeatable')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['radius_attributes', 'app_settings', 'ip_assignments', 'recharges'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
