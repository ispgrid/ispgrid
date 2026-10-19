<?php

namespace App\Models;

use App\Enums\IpRangeKind;
use App\Support\Ipv4Block;
use Database\Factories\IpRangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A block of addresses. IPoE: a subnet on a router port, with the gateway and DNS a technician needs. PPPoE: a
 * subnet or a first-to-last range routed to sessions, every address usable unless the first and last are left out.
 */
#[Fillable(['name', 'kind', 'network', 'leave_out_edges', 'gateway', 'dns1', 'dns2'])]
class IpRange extends Model
{
    /** @use HasFactory<IpRangeFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => IpRangeKind::class,
            'leave_out_edges' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'kind', 'network', 'leave_out_edges', 'gateway', 'dns1', 'dns2'])
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

    public function block(): ?Ipv4Block
    {
        return Ipv4Block::parse($this->network);
    }

    /**
     * The addresses that belong in the inventory: all of a PPPoE range (less the first and last when ticked), the
     * hosts of an IPoE subnet. The gateway is left out by whoever fills the inventory.
     *
     * @return list<string>
     */
    public function usableAddresses(): array
    {
        $block = $this->block();

        if ($block === null) {
            return [];
        }

        $edges = $this->kind === IpRangeKind::Ipoe || $this->leave_out_edges ? $block->edges() : [];

        return array_values(array_diff($block->addresses(), $edges));
    }
}
