<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The single FreeRADIUS settings row (cached 24 h by isp; saving restarts FreeRADIUS).
 */
#[Fillable(['id', 'expire_type', 'disabled_users_pool', 'reject_requests', 'reject_session_timeout',
    'default_session_timeout', 'count_and_reject', 'reject_packet_interval'])]
class RadiusSetting extends Model
{
    protected $table = 'settings';

    public $timestamps = false;

    public $incrementing = false;

    public static function current(): self
    {
        return static::query()->findOrFail(1);
    }
}
