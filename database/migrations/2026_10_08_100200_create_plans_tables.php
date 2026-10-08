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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('validity');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);

            $table->string('upload', 20);
            $table->string('download', 20);
            $table->string('burst_upload', 20)->nullable();
            $table->string('burst_download', 20)->nullable();
            $table->string('threshold_upload', 20)->nullable();
            $table->string('threshold_download', 20)->nullable();
            $table->unsignedSmallInteger('burst_time')->nullable();
            $table->unsignedTinyInteger('priority')->nullable();
            $table->string('min_upload', 20)->nullable();
            $table->string('min_download', 20)->nullable();

            $table->boolean('is_fup')->default(false);
            $table->unsignedInteger('limit_after')->nullable();
            $table->string('fup_upload', 20)->nullable();
            $table->string('fup_download', 20)->nullable();

            $table->unsignedInteger('time_limit_hours')->nullable();
            $table->unsignedInteger('daily_data_gb')->nullable();
            $table->unsignedInteger('daily_time_hours')->nullable();
            $table->unsignedInteger('monthly_data_gb')->nullable();
            $table->unsignedInteger('monthly_time_hours')->nullable();

            $table->string('slot_mode', 20)->nullable();
            $table->string('slot_days', 20)->nullable();
            $table->unsignedTinyInteger('slot_from')->nullable();
            $table->unsignedTinyInteger('slot_to')->nullable();
            $table->boolean('slot_full_days')->default(false);
            $table->string('slot_upload', 20)->nullable();
            $table->string('slot_download', 20)->nullable();

            $table->foreignId('pool_id')->nullable()->constrained();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plan_address_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained();
            $table->foreignId('address_list_id')->constrained();
            $table->unique(['plan_id', 'address_list_id']);
        });

        Schema::create('plan_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained();
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
        foreach (['plan_attributes', 'plan_address_list', 'plans'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
