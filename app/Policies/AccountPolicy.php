<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\User;

/**
 * Customer accounts: one box per action on the Roles screen.
 */
class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAccess(Permission::ViewCustomers);
    }

    public function view(User $user, Account $account): bool
    {
        return $user->hasAccess(Permission::ViewCustomers);
    }

    public function create(User $user): bool
    {
        return $user->hasAccess(Permission::AddCustomers);
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasAccess(Permission::EditCustomers);
    }

    public function recharge(User $user, Account $account): bool
    {
        return $user->hasAccess(Permission::RechargeCustomers);
    }

    public function changeStatus(User $user, Account $account): bool
    {
        return $user->hasAccess(Permission::ChangeCustomerStatus);
    }

    /**
     * Without it the edit form never receives the password and an empty field keeps it.
     */
    public function viewPassword(User $user, Account $account): bool
    {
        return $user->hasAccess(Permission::ViewCustomerPasswords);
    }

    /**
     * Forgetting bound devices and clearing a failed-login lockout.
     */
    public function resetDevices(User $user, Account $account): bool
    {
        return $user->hasAccess(Permission::ResetCustomerDevices);
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->hasAccess(Permission::DeleteCustomers);
    }

    public function restore(User $user, Account $account): bool
    {
        return $user->hasAccess(Permission::DeleteCustomers);
    }
}
