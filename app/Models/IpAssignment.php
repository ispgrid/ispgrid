<?php

namespace App\Models;

use App\Enums\IpAssignmentKind;
use Database\Factories\IpAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An IP or block handed to a STATIC leased line (managed on the router, not in FreeRADIUS).
 */
#[Fillable(['account_id', 'ip_range_id', 'kind', 'network', 'gateway', 'vlan_id', 'notes'])]
class IpAssignment extends Model
{
    /** @use HasFactory<IpAssignmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => IpAssignmentKind::class];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<IpRange, $this>
     */
    public function range(): BelongsTo
    {
        return $this->belongsTo(IpRange::class, 'ip_range_id');
    }
}
