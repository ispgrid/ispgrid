<?php

namespace App\Models;

use App\Billing\Decimal;
use App\Casts\Amount;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Support\Currency;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An issued invoice. Everything it prints (company, customer, tax lines, totals) is a copy taken at issue, so a
 * later change to the customer, plan or settings never changes it. It is never edited, only cancelled.
 */
#[Fillable(['number', 'series', 'sequence', 'account_id', 'recharge_id', 'replaces_invoice_id', 'kind', 'status', 'title', 'issue_date', 'due_date',
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
            'taxable_total' => Amount::class,
            'tax_total' => Amount::class,
            'round_off' => Amount::class,
            'total' => Amount::class,
            'paid_total' => Amount::class,
            'balance' => Amount::class,
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
     * The invoice this one replaced (Cancel and reissue).
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'replaces_invoice_id');
    }

    /**
     * The invoice that replaced this cancelled one.
     *
     * @return HasOne<Invoice, $this>
     */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(Invoice::class, 'replaces_invoice_id');
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
     * Works out what is paid and owed from the payments not removed, and the status from that. A cancelled
     * invoice stays cancelled and owes nothing.
     */
    public function refreshStatus(): void
    {
        if ($this->status === InvoiceStatus::Cancelled) {
            $this->update(['balance' => '0']);

            return;
        }

        $paid = Decimal::round((string) $this->payments()->sum('amount'), Currency::decimals());
        $balance = Decimal::round(Decimal::sub((string) $this->total, $paid), Currency::decimals());

        $this->update([
            'paid_total' => $paid,
            'balance' => $balance,
            'status' => match (true) {
                Decimal::compare($balance, '0') <= 0 => InvoiceStatus::Paid,
                Decimal::compare($paid, '0') > 0 => InvoiceStatus::PartlyPaid,
                default => InvoiceStatus::Unpaid,
            },
        ]);
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
