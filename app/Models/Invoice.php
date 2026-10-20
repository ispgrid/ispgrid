<?php

namespace App\Models;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An issued invoice. Everything it prints (company, customer, tax lines, totals) is a copy taken at issue, so a
 * later change to the customer, plan or settings never changes it. It is never edited, only cancelled.
 */
#[Fillable(['number', 'series', 'sequence', 'account_id', 'recharge_id', 'kind', 'status', 'title', 'issue_date', 'due_date',
    'terms_days', 'period_from', 'period_to', 'validity_text', 'place_of_supply', 'tax_setup', 'item_code_label',
    'company', 'customer', 'taxable_total', 'tax_total', 'round_off', 'total', 'paid_total', 'balance',
    'total_in_words', 'user_id', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'sequence' => 'integer',
            'terms_days' => 'integer',
            'period_from' => 'immutable_datetime',
            'period_to' => 'immutable_datetime',
            'company' => 'array',
            'customer' => 'array',
            'taxable_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'round_off' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'balance' => 'decimal:2',
            'cancelled_at' => 'immutable_datetime',
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
     * @return BelongsTo<Recharge, $this>
     */
    public function recharge(): BelongsTo
    {
        return $this->belongsTo(Recharge::class);
    }

    /**
     * The staff member who issued it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<InvoiceTaxLine, $this>
     */
    public function taxLines(): HasMany
    {
        return $this->hasMany(InvoiceTaxLine::class)->orderBy('position');
    }

    /**
     * The payments not removed, oldest first.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_at')->orderBy('id');
    }
}
