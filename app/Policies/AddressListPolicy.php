<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AddressList;
use App\Models\User;

/**
 * Firewall address lists. Viewing is the shared network box; changes need this page's own box.
 */
class AddressListPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAccess(Permission::ViewNetwork);
    }

    public function create(User $user): bool
    {
        return $user->hasAccess(Permission::ManageAddressLists);
    }

    public function update(User $user, AddressList $addressList): bool
    {
        return $user->hasAccess(Permission::ManageAddressLists);
    }

    public function delete(User $user, AddressList $addressList): bool
    {
        return $user->hasAccess(Permission::ManageAddressLists);
    }

    public function restore(User $user, AddressList $addressList): bool
    {
        return $user->hasAccess(Permission::ManageAddressLists);
    }
}
