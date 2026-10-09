<?php

namespace App\Policies;

use App\Models\Ip;
use App\Models\User;

/**
 * Taking one address out of the inventory and back. All staff are equal in the MVP; roles plug in here later.
 */
class IpPolicy
{
    public function delete(User $user, Ip $ip): bool
    {
        return true;
    }

    public function restore(User $user, Ip $ip): bool
    {
        return true;
    }
}
