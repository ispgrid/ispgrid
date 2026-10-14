<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Customer notifications: provider settings, operator rules, one log row per message, the "No reminders" switch
 * and the three Notifications boxes on the Roles screen (Technician gets the log). FreeRADIUS reads none of this.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['view-notification-log', 'manage-notification-rules', 'manage-notification-providers'];

    public function up(): void
    {
        Schema::create('notification_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->unique();
            $table->boolean('enabled')->default(false);
            $table->text('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('trigger', 50);
            $table->json('trigger_settings')->nullable();
            $table->json('filters')->nullable();
            $table->string('provider', 50);
            $table->json('template')->nullable();
            $table->json('values')->nullable();
            $table->text('approved_text')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['enabled', 'trigger']);
        });

        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_rule_id')->nullable()->constrained();
            $table->foreignId('account_id')->nullable()->constrained();
            $table->string('provider', 50);
            $table->string('channel', 20);
            $table->string('to', 30);
            $table->json('template')->nullable();
            $table->json('values')->nullable();
            $table->boolean('values_trimmed')->default(false);
            $table->text('preview')->nullable();
            $table->string('status', 20);
            $table->string('error', 500)->nullable();
            $table->string('provider_message_id', 100)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('once_key', 191)->nullable()->unique();
            $table->dateTime('sent_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamps();
            $table->index(['account_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('notifications_off')->default(false)->after('remarks');
        });

        $now = now();

        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }

        $technician = DB::table('roles')->where('name', 'Technician')->where('guard_name', 'web')->value('id');
        $viewLog = DB::table('permissions')->where('name', 'view-notification-log')->where('guard_name', 'web')->value('id');

        if ($technician !== null) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $viewLog, 'role_id' => $technician]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('notifications_off');
        });
        Schema::dropIfExists('notification_log');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notification_providers');
    }
};
