<?php

namespace App\Models;

use App\Enums\ServiceKind;
use Carbon\CarbonInterface;
use Database\Factories\CustomerServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Service item a customer takes, on its own clock: paid until a date, renewed by its own invoices. A static IP
 * service owns the customer's fixed IP (ip_id, the same as accounts.ip_id): past paid_until the app stops sending
 * it, the scheduler disconnects once (expired_at) and holds the IP until held_until (empty: until staff release
 * it), then ends the service and frees the IP. An ended service stays for history.
 */
#[Fillable(['account_id', 'item_id', 'ip_id', 'paid_until', 'expired_at', 'held_until', 'ended_at', 'ended_by', 'end_reason'])]
class CustomerService extends Model
{
    /** @use HasFactory<CustomerServiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_until' => 'datetime',
            'expired_at' => 'datetime',
            'held_until' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Ip, $this>
     */
    public function ip(): BelongsTo
    {
        return $this->belongsTo(Ip::class);
    }

    /**
     * @return HasMany<CustomerServiceRenewal, $this>
     */
    public function renewals(): HasMany
    {
        return $this->hasMany(CustomerServiceRenewal::class);
    }

    /**
     * The newest renewal that is not cancelled: the only one Cancel renewal may undo.
     *
     * @return HasOne<CustomerServiceRenewal, $this>
     */
    public function latestRenewal(): HasOne
    {
        return $this->hasOne(CustomerServiceRenewal::class)->ofMany(['id' => 'max'], fn ($query) => $query->whereNull('cancelled_at'));
    }

    public function isStaticIp(): bool
    {
        return $this->item->service_kind === ServiceKind::StaticIp;
    }

    /**
     * active (paid), held (a static IP past paid-until: not sent, still reserved), expired (a plain service past
     * paid-until) or ended.
     *
     * @return 'active'|'held'|'expired'|'ended'
     */
    public function state(CarbonInterface $now): string
    {
        return match (true) {
            $this->ended_at !== null => 'ended',
            $this->paid_until->greaterThan($now) => 'active',
            $this->isStaticIp() => 'held',
            default => 'expired',
        };
    }
}
