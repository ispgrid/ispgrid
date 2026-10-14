<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission list, the Admin and Technician roles, and Admin for everyone who was staff before roles existed
 * (until now all staff could use every screen). The names are written out so later changes to App\Enums\Permission
 * don't change what this did. Running it again adds only what is missing.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'view-customers', 'add-customers', 'edit-customers', 'recharge-customers', 'change-customer-status',
        'delete-customers', 'view-customer-passwords', 'reset-customer-devices',
        'view-online-sessions', 'disconnect-sessions', 'view-login-log',
        'view-plans', 'manage-plans',
        'view-network', 'manage-nas', 'manage-pools', 'manage-ip-ranges', 'manage-address-lists',
        'import-data', 'manage-connection-types', 'manage-app-settings', 'manage-radius-settings', 'view-activity-log',
    ];

    private const TECHNICIAN = [
        'view-customers', 'add-customers', 'edit-customers', 'recharge-customers', 'change-customer-status',
        'delete-customers', 'view-customer-passwords', 'reset-customer-devices',
        'view-online-sessions', 'disconnect-sessions', 'view-login-log',
        'view-plans', 'view-network',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }

        $admin = $this->role('Admin', $now);
        $technician = DB::table('roles')->where('name', 'Technician')->where('guard_name', 'web')->value('id');

        if ($technician === null) {
            $technician = $this->role('Technician', $now);

            foreach (DB::table('permissions')->whereIn('name', self::TECHNICIAN)->pluck('id') as $permission) {
                DB::table('role_has_permissions')->insert(['permission_id' => $permission, 'role_id' => $technician]);
            }
        }

        $userType = 'App\\Models\\User';
        $withRole = DB::table('model_has_roles')->where('model_type', $userType)->pluck('model_id');

        foreach (DB::table('users')->whereNotIn('id', $withRole)->pluck('id') as $user) {
            DB::table('model_has_roles')->insert(['role_id' => $admin, 'model_type' => $userType, 'model_id' => $user]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('model_has_roles')->delete();
        DB::table('role_has_permissions')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();
    }

    private function role(string $name, mixed $now): int
    {
        DB::table('roles')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

        return (int) DB::table('roles')->where('name', $name)->where('guard_name', 'web')->value('id');
    }
};
