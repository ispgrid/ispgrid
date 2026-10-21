<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One customer's usage on one day (the usage database, filled by the radacct triggers): what the NAS received from
 * them (bytes_in, upload), what it sent (bytes_out, download) and the online time. Read only by the app.
 *
 * @property string $username
 * @property Carbon $day
 * @property int $bytes_in
 * @property int $bytes_out
 * @property int $seconds
 */
#[WithoutIncrementing]
#[WithoutTimestamps]
class UsageDay extends Model
{
    protected $primaryKey = 'username';

    public function getTable(): string
    {
        return config('ispgrid.usage_database').'.usage_daily';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'bytes_in' => 'integer',
            'bytes_out' => 'integer',
            'seconds' => 'integer',
        ];
    }
}
