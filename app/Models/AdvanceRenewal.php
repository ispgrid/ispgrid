<?php

namespace App\Models;

use App\Enums\AdvanceRenewalStatus;
use Database\Factories\AdvanceRenewalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A renewal booked in advance: it waits until the customer's current period ends, then starts as a recharge
 * (customers:renewals). Its start always follows the customer's expiry.
 */
#[Fillable(['account_id', 'plan_id', 'amount', 'starts_at', 'expiry', 'note', 'user_id', 'status', 'recharge_id', 'invoice_id'])]
class AdvanceRenewal extends Model
{
    /** @use HasFactory<AdvanceRenewalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'expiry' => 'datetime',
            'status' => AdvanceRenewalStatus::class,
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
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /**
     * The staff member who booked it.
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
