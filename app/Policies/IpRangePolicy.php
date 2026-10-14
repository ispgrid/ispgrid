<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\IpRange;
use App\Models\User;

/**
 * IP ranges and their inventory. Viewing is the shared network box; changes need Manage IP ranges.
 */
class IpRangePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAccess(Permission::ViewNetwork);
    }

    public function view(User $user, IpRange $ipRange): bool
    {
        return $user->hasAccess(Permission::ViewNetwork);
    }

    public function create(User $user): bool
    {
        return $user->hasAccess(Permission::ManageIpRanges);
    }

    public function update(User $user, IpRange $ipRange): bool
    {
        return $user->hasAccess(Permission::ManageIpRanges);
    }

    public function delete(User $user, IpRange $ipRange): bool
    {
        return $user->hasAccess(Permission::ManageIpRanges);
    }

    public function restore(User $user, IpRange $ipRange): bool
    {
        return $user->hasAccess(Permission::ManageIpRanges);
    }
}
