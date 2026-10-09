<?php

namespace App\Policies;

use App\Models\IpRange;
use App\Models\User;

/**
 * IP ranges and their inventory. All staff are equal in the MVP; roles plug in here later.
 */
class IpRangePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, IpRange $ipRange): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, IpRange $ipRange): bool
    {
        return true;
    }

    public function delete(User $user, IpRange $ipRange): bool
    {
        return true;
    }

    public function restore(User $user, IpRange $ipRange): bool
    {
        return true;
    }
}
