<?php

namespace App\Models;

use App\Casts\Amount;
use App\Enums\OnlinePaymentOrigin;
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
 * @property OnlinePaymentOrigin $origin
 * @property string|null $gateway_order_id
 * @property array<string, mixed>|null $gateway_response
 * @property array<string, mixed>|null $gateway_start
 * @property Carbon|null $paid_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $handled_at
 */
#[Fillable(['public_id', 'account_id', 'gateway', 'origin', 'gateway_order_id', 'amount', 'dues_amount', 'renewal_amount', 'currency', 'renewal_kind',
    'renewal_plan_id', 'renewal_expiry_key', 'status', 'gateway_txn_id', 'gateway_status', 'gateway_response', 'paid_at', 'settled_at', 'note',
    'handled_at', 'handled_by', 'handled_note', 'client_ip', 'gateway_start'])]
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
            'origin' => OnlinePaymentOrigin::class,
            'amount' => Amount::class,
            'dues_amount' => Amount::class,
            'renewal_amount' => Amount::class,
            'gateway_response' => 'array',
            'gateway_start' => 'encrypted:array',
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * How the attempt reads to the customer: received (paid, or paid and waiting for staff), pending or failed.
     *
     * @return 'received'|'pending'|'failed'
     */
    public function publicState(): string
    {
        return match ($this->status) {
            OnlinePaymentStatus::Paid, OnlinePaymentStatus::NeedsAttention => 'received',
            OnlinePaymentStatus::Pending => 'pending',
            OnlinePaymentStatus::Failed => 'failed',
        };
    }

    /**
     * The ids of the payments this paid attempt recorded (none until it is paid).
     *
     * @return list<int>
     */
    public function receiptIds(): array
    {
        if ($this->status !== OnlinePaymentStatus::Paid) {
            return [];
        }

        $ids = Payment::query()->where('account_id', $this->account_id)->where('reference', $this->gateway_txn_id ?? $this->public_id)
            ->whereHas('method', fn ($query) => $query->where('gateway', $this->gateway))
            ->orderBy('id')->pluck('id')->all();

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * The receipt numbers (RCPT-n) of receiptIds().
     *
     * @return list<string>
     */
    public function receiptNumbers(): array
    {
        return array_map(fn (int $id): string => 'RCPT-'.$id, $this->receiptIds());
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
