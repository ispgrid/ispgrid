<?php

namespace App\Models;

use Database\Factories\IpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One address of the IP inventory. Soft deleted = taken out of the inventory (never handed out).
 */
#[Fillable(['ip_range_id', 'address', 'is_used'])]
class Ip extends Model
{
    /** @use HasFactory<IpFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Adding a range creates every address at once: only removing and restoring one is recorded.
     *
     * @var list<string>
     */
    protected static array $recordEvents = ['deleted', 'restored'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_used' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['address']);
    }

    /**
     * @return BelongsTo<IpRange, $this>
     */
    public function range(): BelongsTo
    {
        return $this->belongsTo(IpRange::class, 'ip_range_id');
    }

    /**
     * The customer that has this address as static IP (accounts.ip_id).
     *
     * @return HasOne<Account, $this>
     */
    public function account(): HasOne
    {
        return $this->hasOne(Account::class);
    }

    /**
     * Addresses given to a customer: flagged by the sync, or set as an account's static IP.
     *
     * @param  Builder<Ip>  $query
     */
    #[Scope]
    protected function givenOut(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->where('is_used', true)->orWhereHas('account'));
    }
}
