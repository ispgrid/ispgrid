<?php

namespace App\Policies;

use App\Models\AddressList;
use App\Models\User;

/**
 * Firewall address lists. All staff are equal in the MVP; roles plug in here later.
 */
class AddressListPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AddressList $addressList): bool
    {
        return true;
    }

    public function delete(User $user, AddressList $addressList): bool
    {
        return true;
    }

    public function restore(User $user, AddressList $addressList): bool
    {
        return true;
    }
}
