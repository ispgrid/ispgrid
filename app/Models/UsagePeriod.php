<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One customer's usage since their last recharge (the usage database): the total that data and time limits are
 * checked against. The radacct triggers add to it; a recharge starts it again from zero.
 *
 * @property string $username
 * @property Carbon|null $since
 * @property int $bytes
 * @property int $seconds
 */
#[WithoutIncrementing]
#[WithoutTimestamps]
class UsagePeriod extends Model
{
    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public function getTable(): string
    {
        return config('ispgrid.usage_database').'.usage_period';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'since' => 'datetime',
            'bytes' => 'integer',
            'seconds' => 'integer',
        ];
    }

    /**
     * Starts the customer's usage period again at $since, at zero. Runs in the caller's transaction (the usage
     * database is on the same connection), so a recharge and its new period are saved together.
     */
    public static function restart(string $username, CarbonInterface $since): void
    {
        DB::table((new self)->getTable())->upsert(
            [['username' => $username, 'since' => $since->format('Y-m-d H:i:s'), 'bytes' => 0, 'seconds' => 0]],
            ['username'],
            ['since', 'bytes', 'seconds'],
        );
    }
}
