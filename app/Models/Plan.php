<?php

namespace App\Models;

use App\Enums\SlotMode;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service plan. Its slug is the FreeRADIUS group name and never changes.
 */
#[Fillable(['slug', 'name', 'validity', 'description', 'price',
    'upload', 'download', 'burst_upload', 'burst_download', 'threshold_upload', 'threshold_download',
    'burst_time', 'priority', 'min_upload', 'min_download',
    'is_fup', 'limit_after', 'fup_upload', 'fup_download',
    'time_limit_hours', 'daily_data_gb', 'daily_time_hours', 'monthly_data_gb', 'monthly_time_hours',
    'slot_mode', 'slot_days', 'slot_from', 'slot_to', 'slot_full_days', 'slot_upload', 'slot_download',
    'pool_id'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_fup' => 'boolean',
            'slot_full_days' => 'boolean',
            'slot_mode' => SlotMode::class,
        ];
    }

    /**
     * @return BelongsTo<Pool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    /**
     * @return BelongsToMany<AddressList, $this>
     */
    public function addressLists(): BelongsToMany
    {
        return $this->belongsToMany(AddressList::class, 'plan_address_list');
    }

    /**
     * Custom RADIUS reply attributes of this plan.
     *
     * @return HasMany<PlanAttribute, $this>
     */
    public function radiusAttributes(): HasMany
    {
        return $this->hasMany(PlanAttribute::class);
    }
}
