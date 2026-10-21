<?php

namespace App\Models;

use App\Enums\RechargeKind;
use Database\Factories\RechargeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recharge of an account: its kind, the plan, the expiry before and after (or the validity left waiting for
 * the first login), and who did it.
 */
#[Fillable(['account_id', 'plan_id', 'kind', 'old_expiry', 'new_expiry', 'new_pending_validity', 'amount', 'invoice_id', 'note', 'user_id'])]
class Recharge extends Model
{
    /** @use HasFactory<RechargeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_expiry' => 'datetime',
            'new_expiry' => 'datetime',
            'new_pending_validity' => 'integer',
            'amount' => 'decimal:2',
            'kind' => RechargeKind::class,
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The staff member who recharged.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The invoice issued for it, if any.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
