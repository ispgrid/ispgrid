<?php

namespace App\Policies;

use App\Models\Nas;
use App\Models\User;

/**
 * Routers. All staff are equal in the MVP; roles plug in here later.
 */
class NasPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Nas $nas): bool
    {
        return true;
    }

    public function delete(User $user, Nas $nas): bool
    {
        return true;
    }

    public function restore(User $user, Nas $nas): bool
    {
        return true;
    }
}
