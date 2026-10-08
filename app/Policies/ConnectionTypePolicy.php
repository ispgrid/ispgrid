<?php

namespace App\Policies;

use App\Models\ConnectionType;
use App\Models\User;

/**
 * Connection types (Home, Business, ...). All staff are equal in the MVP; roles plug in here later.
 */
class ConnectionTypePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ConnectionType $connectionType): bool
    {
        return true;
    }

    public function delete(User $user, ConnectionType $connectionType): bool
    {
        return true;
    }

    public function restore(User $user, ConnectionType $connectionType): bool
    {
        return true;
    }
}
