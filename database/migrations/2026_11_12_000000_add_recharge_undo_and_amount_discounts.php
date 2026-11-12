<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cancel recharge and amount discounts: what a recharge changed (old plan, first-login validity, status) so it
 * can be undone, who cancelled it and why; a customer's saved discount as an amount; and the "Cancel recharges"
 * box, given to roles that can already cancel invoices. FreeRADIUS reads none of this.
 */
return new class extends Migration
{
    private const PERMISSION = 'cancel-recharges';

    public function up(): void
    {
        Schema::table('recharges', function (Blueprint $table) {
            $table->foreignId('old_plan_id')->nullable()->after('plan_id')->constrained('plans')->nullOnDelete();
            $table->unsignedInteger('old_pending_validity')->nullable()->after('new_pending_validity');
            $table->unsignedTinyInteger('old_status')->nullable()->after('old_pending_validity');
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('discount_type', 10)->default('percent')->after('billing_free');
            $table->decimal('discount_amount', 13, 3)->default(0)->after('discount_percent');
        });

        $now = now();
        DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

        $this->grant();
    }

    /**
     * Gives the new box to each role that can already cancel invoices, never a role picked by name.
     */
    public function grant(): void
    {
        $permission = fn (string $name): ?int => DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
        $box = $permission(self::PERMISSION);

        foreach (DB::table('role_has_permissions')->where('permission_id', $permission('cancel-invoices'))->pluck('role_id') as $role) {
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

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_amount']);
        });

        Schema::table('recharges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('old_plan_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['old_pending_validity', 'old_status', 'cancelled_at', 'cancel_reason']);
        });
    }
};
