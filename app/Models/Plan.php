<?php

namespace App\Models;

use App\Casts\Amount;
use App\Enums\SlotMode;
use App\Enums\ValidityUnit;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A service plan. Its slug is the FreeRADIUS group name and never changes.
 */
#[Fillable(['slug', 'name', 'validity', 'validity_unit', 'hotspot_starts_at_first_login', 'description', 'price',
    'charge_tax', 'round_off', 'item_code',
    'upload', 'download', 'burst_upload', 'burst_download', 'threshold_upload', 'threshold_download',
    'burst_time', 'priority', 'min_upload', 'min_download',
    'is_fup', 'limit_after', 'fup_upload', 'fup_download',
    'time_limit_hours', 'daily_data_gb', 'daily_time_hours', 'monthly_data_gb', 'monthly_time_hours',
    'slot_mode', 'slot_days', 'slot_from', 'slot_to', 'slot_full_days', 'slot_upload', 'slot_download',
    'pool_id'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The database defaults, so a plan created in this request does not log them as changed on its first update.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'validity_unit' => 'days',
        'charge_tax' => true,
        'round_off' => false,
        'hotspot_starts_at_first_login' => false,
        'is_fup' => false,
        'slot_full_days' => false,
    ];

    /**
     * Every column staff edit on the plan form. Address lists and custom attributes live in other tables; the
     * form logs their changes itself.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly([
            'slug', 'name', 'validity', 'validity_unit', 'hotspot_starts_at_first_login', 'description', 'price',
            'charge_tax', 'round_off', 'item_code',
            'upload', 'download', 'burst_upload', 'burst_download', 'threshold_upload', 'threshold_download',
            'burst_time', 'priority', 'min_upload', 'min_download',
            'is_fup', 'limit_after', 'fup_upload', 'fup_download',
            'time_limit_hours', 'daily_data_gb', 'daily_time_hours', 'monthly_data_gb', 'monthly_time_hours',
            'slot_mode', 'slot_days', 'slot_from', 'slot_to', 'slot_full_days', 'slot_upload', 'slot_download',
            'pool_id',
        ])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validity' => 'integer',
            'validity_unit' => ValidityUnit::class,
            'hotspot_starts_at_first_login' => 'boolean',
            'price' => Amount::class,
            'charge_tax' => 'boolean',
            'round_off' => 'boolean',
            'burst_time' => 'integer',
            'priority' => 'integer',
            'is_fup' => 'boolean',
            'limit_after' => 'integer',
            'time_limit_hours' => 'integer',
            'daily_data_gb' => 'integer',
            'daily_time_hours' => 'integer',
            'monthly_data_gb' => 'integer',
            'monthly_time_hours' => 'integer',
            'slot_mode' => SlotMode::class,
            'slot_from' => 'integer',
            'slot_to' => 'integer',
            'slot_full_days' => 'boolean',
            'pool_id' => 'integer',
        ];
    }

    /**
     * The validity in minutes.
     */
    public function validityMinutes(): int
    {
        return $this->validity * $this->validity_unit->minutes();
    }

    /**
     * "30 days", "3 hours", or "3 hours from first login" for a first-login plan.
     */
    public function validityLabel(): string
    {
        $validity = $this->validity_unit->count($this->validity);

        return $this->hotspot_starts_at_first_login ? __(':validity from first login', ['validity' => $validity]) : $validity;
    }

    /**
     * Customers on this plan (live ones; deleted accounts are left out by the soft-delete scope).
     *
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
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
