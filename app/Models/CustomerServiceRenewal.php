<?php

namespace App\Models;

use App\Casts\Amount;
use Database\Factories\CustomerServiceRenewalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sale of a customer service: the first one (Add service, old_paid_until empty) or a renewal, with its months,
 * amount, the paid-until date before and after, and its invoice (none while invoicing is off). A cancelled row stays,
 * marked.
 */
#[Fillable(['customer_service_id', 'invoice_id', 'months', 'amount', 'old_paid_until', 'new_paid_until', 'cancelled_at',
    'cancelled_by', 'cancel_reason', 'created_by'])]
class CustomerServiceRenewal extends Model
{
    /** @use HasFactory<CustomerServiceRenewalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'amount' => Amount::class,
            'old_paid_until' => 'datetime',
            'new_paid_until' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CustomerService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(CustomerService::class, 'customer_service_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
