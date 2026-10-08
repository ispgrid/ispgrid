<?php

namespace App\Models;

use Database\Factories\IpoeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IP to username lookup for IPoE, read by isp. Written by the account sync, never by hand.
 */
#[Fillable(['account_id', 'username', 'ip_address'])]
class Ipoe extends Model
{
    /** @use HasFactory<IpoeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
