<?php

namespace App\Models;

use App\Support\RadiusText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The single FreeRADIUS settings row (cached 24 h by isp; saving restarts FreeRADIUS).
 * hotspot_enabled is the exception: App settings writes it, and isp reads it on every Hotspot page login.
 */
#[Fillable(['id', 'expire_type', 'disabled_users_pool', 'reject_requests', 'reject_session_timeout',
    'default_session_timeout', 'count_and_reject', 'reject_packet_interval', 'hotspot_enabled'])]
class RadiusSetting extends Model
{
    use LogsActivity;

    protected $table = 'settings';

    public $timestamps = false;

    public $incrementing = false;

    /**
     * isp reads the disabled pool name and sends it to the router as Framed-Pool.
     */
    protected static function booted(): void
    {
        static::saving(fn (self $settings) => RadiusText::check('settings', $settings->only(['disabled_users_pool'])));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['hotspot_enabled' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->findOrFail(1);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['expire_type', 'disabled_users_pool', 'reject_requests', 'reject_session_timeout',
                'default_session_timeout', 'count_and_reject', 'reject_packet_interval', 'hotspot_enabled'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
