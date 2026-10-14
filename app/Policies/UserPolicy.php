<?php

namespace App\Policies;

use App\Models\User;

/**
 * Staff management stays with Admin and is not a box on the Roles screen, so no role can grant itself more.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Disabling yourself is refused. With changeRole() below, the Admin acting is always an active Admin, so the
     * last one can never be disabled or demoted.
     */
    public function delete(User $user, User $staff): bool
    {
        return $user->isAdmin() && $user->isNot($staff);
    }

    public function restore(User $user, User $staff): bool
    {
        return $user->isAdmin();
    }

    public function changeRole(User $user, User $staff): bool
    {
        return $user->isAdmin() && $user->isNot($staff);
    }
}
