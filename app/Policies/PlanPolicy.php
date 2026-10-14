<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Plan;
use App\Models\User;

/**
 * Service plans: one box to see them, one to change them.
 */
class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAccess(Permission::ViewPlans);
    }

    public function create(User $user): bool
    {
        return $user->hasAccess(Permission::ManagePlans);
    }

    public function update(User $user, Plan $plan): bool
    {
        return $user->hasAccess(Permission::ManagePlans);
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->hasAccess(Permission::ManagePlans);
    }

    public function restore(User $user, Plan $plan): bool
    {
        return $user->hasAccess(Permission::ManagePlans);
    }
}
