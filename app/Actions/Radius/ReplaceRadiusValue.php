<?php

namespace App\Actions\Radius;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites one attribute's value in the FreeRADIUS rows of the given customers or plans: a router's new IP
 * in Isp-Nas, a pool's new name in Framed-Pool, an address list's new name. Call it inside the save's
 * transaction. Plans 6 and 7 can replace these calls with their full syncs.
 */
final class ReplaceRadiusValue
{
    /**
     * @param  'radcheck'|'radreply'|'radgroupreply'  $table
     * @param  iterable<int, string>  $owners  usernames, or plan slugs for radgroupreply
     * @return int rows changed
     */
    public function handle(string $table, iterable $owners, string $attribute, string $from, string $to): int
    {
        $ownerColumn = $table === 'radgroupreply' ? 'groupname' : 'username';

        return collect($owners)
            ->unique()
            ->chunk(1000)
            ->sum(fn (Collection $chunk): int => DB::table($table)
                ->whereIn($ownerColumn, $chunk->values()->all())
                ->where('attribute', $attribute)
                ->where('value', $from)
                ->update(['value' => $to]));
    }
}
