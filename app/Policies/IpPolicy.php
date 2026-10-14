<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Ip;
use App\Models\User;

/**
 * Taking one address out of the inventory and back is part of managing IP ranges.
 */
class IpPolicy
{
    public function delete(User $user, Ip $ip): bool
    {
        return $user->hasAccess(Permission::ManageIpRanges);
    }

    public function restore(User $user, Ip $ip): bool
    {
        return $user->hasAccess(Permission::ManageIpRanges);
    }
}
