<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Pool;
use App\Models\User;

/**
 * Router address pools. Viewing is the shared network box; changes need this page's own box.
 */
class PoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAccess(Permission::ViewNetwork);
    }

    public function create(User $user): bool
    {
        return $user->hasAccess(Permission::ManagePools);
    }

    public function update(User $user, Pool $pool): bool
    {
        return $user->hasAccess(Permission::ManagePools);
    }

    public function delete(User $user, Pool $pool): bool
    {
        return $user->hasAccess(Permission::ManagePools);
    }

    public function restore(User $user, Pool $pool): bool
    {
        return $user->hasAccess(Permission::ManagePools);
    }
}
