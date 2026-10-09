<?php

namespace App\Models;

use Database\Factories\IpRangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A block of addresses (CIDR) with the gateway and DNS a technician needs.
 */
#[Fillable(['name', 'network', 'gateway', 'dns1', 'dns2'])]
class IpRange extends Model
{
    /** @use HasFactory<IpRangeFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'network', 'gateway', 'dns1', 'dns2'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return HasMany<Ip, $this>
     */
    public function ips(): HasMany
    {
        return $this->hasMany(Ip::class);
    }
}
