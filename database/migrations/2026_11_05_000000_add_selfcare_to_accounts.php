<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Selfcare: the customer's own login password (a hash, separate from the PPPoE password) and the "Set selfcare
 * passwords" box on the Roles screen. Selfcare stays off (selfcare.enabled is not set), so nothing changes until an
 * operator turns it on. FreeRADIUS reads none of this.
 */
return new class extends Migration
{
    private const PERMISSION = 'set-selfcare-passwords';

    public function up(): void
    {
        // down() keeps the columns, so running up() again after a rollback finds them in place.
        if (! Schema::hasColumn('accounts', 'selfcare_password')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->string('selfcare_password')->nullable();
                $table->boolean('selfcare_must_change')->default(false);
                $table->dateTime('selfcare_password_set_at')->nullable();
                $table->dateTime('selfcare_last_login_at')->nullable();
            });
        }

        $now = now();
        DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

        $this->grant();
    }

    /**
     * Gives the new box to each role that can already edit customers, never a role picked by name.
     */
    public function grant(): void
    {
        $permission = fn (string $name): ?int => DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
        $box = $permission(self::PERMISSION);

        foreach (DB::table('role_has_permissions')->where('permission_id', $permission('edit-customers'))->pluck('role_id') as $role) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $box, 'role_id' => $role]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Removes the box. The columns stay: dropping them would lose every customer's selfcare password.
     */
    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');
        DB::table('role_has_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
