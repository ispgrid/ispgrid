<?php

namespace App\Models;

use App\Casts\Amount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an invoice, as issued.
 */
#[Fillable(['invoice_id', 'position', 'description', 'sub_description', 'item_code', 'quantity', 'unit_price',
    'discount_percent', 'discount_amount', 'taxable', 'taxed'])]
class InvoiceLine extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:3',
            'unit_price' => Amount::class,
            'discount_percent' => 'decimal:2',
            'discount_amount' => Amount::class,
            'taxable' => Amount::class,
            'taxed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
