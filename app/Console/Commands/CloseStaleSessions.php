<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Closes radacct sessions whose router stopped sending accounting (router or device gone without a Stop).
 * The Hotspot online-device limit and the online list count open sessions, so these ghosts must end.
 * Routers send interim updates every 5 minutes; 15 minutes of silence means the session is gone.
 */
#[Signature('radius:close-stale
    {--minutes=15 : Close sessions with no accounting update for this many minutes}')]
#[Description('Close sessions whose router stopped sending accounting updates')]
class CloseStaleSessions extends Command
{
    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');

        if ($minutes < 1) {
            $this->error('--minutes must be at least 1.');

            return self::FAILURE;
        }

        $closed = DB::table('radacct')
            ->whereNull('acctstoptime')
            ->whereRaw('COALESCE(acctupdatetime, acctstarttime) < NOW() - INTERVAL ? MINUTE', [$minutes])
            ->update([
                'acctstoptime' => DB::raw('COALESCE(acctupdatetime, acctstarttime)'),
                'acctterminatecause' => 'Stale-Session',
            ]);

        $this->info("Closed {$closed} stale ".Str::plural('session', $closed).'.');

        return self::SUCCESS;
    }
}
