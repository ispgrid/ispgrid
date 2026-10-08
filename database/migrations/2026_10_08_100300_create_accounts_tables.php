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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->unique();
            $table->string('password', 253)->nullable();

            $table->string('title', 20)->nullable();
            $table->string('fullname', 100);
            $table->string('company')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('alt_number', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->string('business_id', 30)->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('address_id')->nullable()->constrained();
            $table->foreignId('connection_type_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained();

            $table->string('service_type', 10);
            $table->unsignedSmallInteger('mac_type')->default(1);
            $table->foreignId('plan_id')->nullable()->constrained();
            $table->unsignedInteger('nas_id')->nullable();
            $table->foreign('nas_id')->references('id')->on('nas');
            $table->unsignedTinyInteger('status_id')->default(1);
            $table->dateTime('expiry')->nullable();
            $table->dateTime('usage_since')->nullable();

            $table->foreignId('ip_id')->nullable()->unique()->constrained();
            $table->foreignId('pool_id')->nullable()->constrained();

            $table->boolean('override_plan')->default(false);
            $table->string('override_upload', 20)->nullable();
            $table->string('override_download', 20)->nullable();
            $table->string('override_burst_upload', 20)->nullable();
            $table->string('override_burst_download', 20)->nullable();
            $table->string('override_threshold_upload', 20)->nullable();
            $table->string('override_threshold_download', 20)->nullable();
            $table->unsignedSmallInteger('override_burst_time')->nullable();
            $table->unsignedTinyInteger('override_priority')->nullable();
            $table->string('override_min_upload', 20)->nullable();
            $table->string('override_min_download', 20)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status_id');
            $table->index('expiry');
        });

        Schema::create('ipoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained();
            $table->string('username', 64);
            $table->string('ip_address', 39)->unique();
            $table->timestamps();
        });

        Schema::create('account_address_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('address_list_id')->constrained();
            $table->unique(['account_id', 'address_list_id']);
        });

        Schema::create('account_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->string('attribute', 64);
            $table->char('op', 2);
            $table->string('value', 253);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['account_attributes', 'account_address_list', 'ipoes', 'accounts'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
