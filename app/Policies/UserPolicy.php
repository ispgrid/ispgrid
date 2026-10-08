<?php

namespace App\Policies;

use App\Models\User;

/**
 * Staff management. All staff are equal in the MVP; roles plug in here later.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Disabling yourself is refused, so at least one active staff member always remains.
     */
    public function delete(User $user, User $staff): bool
    {
        return $user->isNot($staff);
    }

    public function restore(User $user, User $staff): bool
    {
        return true;
    }
}
