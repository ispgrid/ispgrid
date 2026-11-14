<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Items: the catalog of what the operator sells (goods and services), used to fill invoice lines. A name is unique
 * among active items only, through a stored column that is empty for inactive rows. Adds the "Manage items" box,
 * given to roles that already manage billing. FreeRADIUS reads none of this.
 */
return new class extends Migration
{
    private const PERMISSION = 'manage-items';

    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 10);
            $table->string('unit', 20)->nullable();
            $table->string('item_code', 20)->nullable();
            $table->boolean('taxable')->default(true);
            $table->decimal('price', 13, 3);
            $table->string('description', 255)->nullable();
            $table->string('sold_as', 10)->default('one_time');
            $table->string('service_kind', 10)->nullable();
            $table->boolean('active')->default(true);
            $table->string('active_name', 100)->nullable()->storedAs('IF(`active`, `name`, NULL)');
            $table->timestamps();

            $table->unique('active_name');
        });

        $now = now();
        DB::table('permissions')->insertOrIgnore(['name' => self::PERMISSION, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

        $this->grant();
    }

    /**
     * Gives the new box to each role that can already manage billing, never a role picked by name.
     */
    public function grant(): void
    {
        $permission = fn (string $name): ?int => DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
        $box = $permission(self::PERMISSION);

        foreach (DB::table('role_has_permissions')->where('permission_id', $permission('manage-billing'))->pluck('role_id') as $role) {
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

        Schema::dropIfExists('items');
    }
};
