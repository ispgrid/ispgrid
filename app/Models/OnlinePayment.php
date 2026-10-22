<?php

namespace App\Models;

use App\Casts\Amount;
use App\Enums\OnlinePaymentStatus;
use Database\Factories\OnlinePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One quick pay attempt: what the customer was asked to pay (dues, and a renewal of their plan), through which
 * gateway, and what the gateway said. public_id is the gateway's order id and the only id in public URLs.
 *
 * @property OnlinePaymentStatus $status
 * @property array<string, mixed>|null $gateway_response
 * @property Carbon|null $paid_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $handled_at
 */
#[Fillable(['public_id', 'account_id', 'gateway', 'amount', 'dues_amount', 'renewal_amount', 'currency', 'renewal_kind',
    'renewal_plan_id', 'renewal_expiry_key', 'status', 'gateway_txn_id', 'gateway_status', 'gateway_response', 'paid_at', 'settled_at', 'note',
    'handled_at', 'handled_by', 'handled_note'])]
class OnlinePayment extends Model
{
    /** @use HasFactory<OnlinePaymentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OnlinePaymentStatus::class,
            'amount' => Amount::class,
            'dues_amount' => Amount::class,
            'renewal_amount' => Amount::class,
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
            'handled_at' => 'datetime',
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
     * The staff member who dealt with a Needs attention payment.
     *
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function renewalPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'renewal_plan_id');
    }
}
