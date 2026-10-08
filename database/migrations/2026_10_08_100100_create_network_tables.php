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
        Schema::create('pools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ip_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('network', 43)->unique();
            $table->string('gateway', 39)->nullable();
            $table->string('dns1', 39)->nullable();
            $table->string('dns2', 39)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_range_id')->constrained();
            $table->string('address', 39)->unique();
            $table->boolean('is_used')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('address_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('connection_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('area', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 60)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['addresses', 'connection_types', 'address_lists', 'ips', 'ip_ranges', 'pools'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
