<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Nas;
use App\Models\User;

/**
 * NAS (the routers FreeRADIUS answers); changes restart FreeRADIUS. Viewing is the shared network box,
 * changes need this page's own box.
 */
class NasPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAccess(Permission::ViewNetwork);
    }

    public function create(User $user): bool
    {
        return $user->hasAccess(Permission::ManageNas);
    }

    public function update(User $user, Nas $nas): bool
    {
        return $user->hasAccess(Permission::ManageNas);
    }

    public function delete(User $user, Nas $nas): bool
    {
        return $user->hasAccess(Permission::ManageNas);
    }

    public function restore(User $user, Nas $nas): bool
    {
        return $user->hasAccess(Permission::ManageNas);
    }
}
