<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Customer services: a Service item a customer takes (a static IP, OTT, local networking), with its own paid-until
 * date, one renewal row per sale and one invoice per renewal. A static IP service owns the customer's accounts.ip_id;
 * once paid-until passes the app stops sending Framed-IP-Address (no FreeRADIUS change) and the IP stays held.
 * Adds the "Manage customer services" box, given to roles that can already recharge.
 */
return new class extends Migration
{
    private const PERMISSION = 'manage-customer-services';

    public function up(): void
    {
        Schema::create('customer_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('ip_id')->nullable()->constrained('ips');
            $table->dateTime('paid_until');
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('held_until')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['ended_at', 'paid_until']);
        });

        Schema::create('customer_service_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_service_id')->constrained();
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->unsignedTinyInteger('months');
            $table->decimal('amount', 13, 3);
            $table->dateTime('old_paid_until')->nullable();
            $table->dateTime('new_paid_until');
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('customer_service_id')->nullable()->after('account_id')->constrained();
        });

        $now = now();
        DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

        $this->grant();
    }

    /**
     * Gives the new box to each role that can already recharge customers, never a role picked by name.
     */
    public function grant(): void
    {
        $permission = fn (string $name): ?int => DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
        $box = $permission(self::PERMISSION);

        foreach (DB::table('role_has_permissions')->where('permission_id', $permission('recharge-customers'))->pluck('role_id') as $role) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $box, 'role_id' => $role]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_service_id');
        });
        Schema::dropIfExists('customer_service_renewals');
        Schema::dropIfExists('customer_services');
    }
};
