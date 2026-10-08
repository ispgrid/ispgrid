<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * Passkeys of active staff only. A disabled staff member's passkey is "not recognized" by the passkey login
 * instead of failing with a server error on a missing user.
 */
class Passkey extends BasePasskey
{
    protected static function booted(): void
    {
        static::addGlobalScope('active_staff', fn (Builder $query) => $query->whereHas('user'));
    }
}
