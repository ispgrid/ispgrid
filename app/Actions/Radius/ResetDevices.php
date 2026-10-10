<?php

namespace App\Actions\Radius;

use Illuminate\Support\Facades\DB;

/**
 * Forgets the devices FreeRADIUS bound to an account (account_macs), so the next device to log in is bound.
 * The only write the app makes to account_macs (app-integration.md section 6).
 */
final class ResetDevices
{
    /**
     * @return list<string> the MACs that were bound (12 upper-case hex digits)
     */
    public function handle(string $username): array
    {
        $macs = DB::table('account_macs')->where('username', $username)->orderBy('mac_norm')->pluck('mac_norm')->all();

        DB::table('account_macs')->where('username', $username)->delete();

        return array_values(array_map('strval', $macs));
    }
}
