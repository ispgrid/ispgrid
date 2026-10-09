<?php

namespace App\Policies;

use App\Models\Pool;
use App\Models\User;

/**
 * Router address pools. All staff are equal in the MVP; roles plug in here later.
 */
class PoolPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Pool $pool): bool
    {
        return true;
    }

    public function delete(User $user, Pool $pool): bool
    {
        return true;
    }

    public function restore(User $user, Pool $pool): bool
    {
        return true;
    }
}
