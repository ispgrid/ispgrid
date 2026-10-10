<?php

namespace App\Actions\Radius;

use Illuminate\Support\Facades\DB;

/**
 * Clears an account's failed-login counters (reject_counters), lifting a lock after too many wrong passwords.
 */
final class UnlockAccount
{
    /**
     * @return int counter rows removed
     */
    public function handle(string $username): int
    {
        return DB::table('reject_counters')->where('username', $username)->delete();
    }
}
