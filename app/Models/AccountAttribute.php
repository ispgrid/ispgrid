<?php

namespace App\Models;

use Database\Factories\AccountAttributeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom RADIUS reply attribute on an account (radreply).
 */
#[Fillable(['account_id', 'attribute', 'op', 'value'])]
class AccountAttribute extends Model
{
    /** @use HasFactory<AccountAttributeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
