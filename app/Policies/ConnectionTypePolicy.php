<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ConnectionType;
use App\Models\User;

/**
 * Connection types (Home, Business, ...): one box for the whole screen. The customer form lists them for
 * everyone who edits customers.
 */
class ConnectionTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAccess(Permission::ManageConnectionTypes);
    }

    public function create(User $user): bool
    {
        return $user->hasAccess(Permission::ManageConnectionTypes);
    }

    public function update(User $user, ConnectionType $connectionType): bool
    {
        return $user->hasAccess(Permission::ManageConnectionTypes);
    }

    public function delete(User $user, ConnectionType $connectionType): bool
    {
        return $user->hasAccess(Permission::ManageConnectionTypes);
    }

    public function restore(User $user, ConnectionType $connectionType): bool
    {
        return $user->hasAccess(Permission::ManageConnectionTypes);
    }
}
