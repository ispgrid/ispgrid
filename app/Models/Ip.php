<?php

namespace App\Models;

use Database\Factories\IpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One address of the IP inventory.
 */
#[Fillable(['ip_range_id', 'address', 'is_used'])]
class Ip extends Model
{
    /** @use HasFactory<IpFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_used' => 'boolean'];
    }

    /**
     * @return BelongsTo<IpRange, $this>
     */
    public function range(): BelongsTo
    {
        return $this->belongsTo(IpRange::class, 'ip_range_id');
    }
}
