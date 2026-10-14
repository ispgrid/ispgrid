<?php

namespace App\Models;

use App\Enums\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A staff role. Admin is built in and holds no rows: User::hasAccess() lets it do everything. Technician is seeded
 * with defaults the admin may change.
 */
class Role extends SpatieRole
{
    public const ADMIN = 'Admin';

    public const TECHNICIAN = 'Technician';

    public function isAdmin(): bool
    {
        return $this->name === self::ADMIN;
    }

    /**
     * The built-in names are translated; a name an admin typed shows as typed.
     */
    public function label(): string
    {
        return in_array($this->name, [self::ADMIN, self::TECHNICIAN], true) ? __($this->name) : $this->name;
    }

    /**
     * Permission names as their labels, in screen order, for the activity log.
     *
     * @param  list<string>  $names
     */
    public static function permissionLabels(array $names): string
    {
        return collect(Permission::cases())
            ->filter(fn (Permission $permission): bool => in_array($permission->value, $names, true))
            ->map(fn (Permission $permission): string => $permission->label())
            ->implode(', ');
    }
}
