<?php

namespace App\Models;

use App\Casts\Amount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax line of an invoice (CGST 9%, IGST 18%, VAT 20%, ...), as issued.
 */
#[Fillable(['invoice_id', 'position', 'name', 'rate', 'amount'])]
class InvoiceTaxLine extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'rate' => 'decimal:3',
            'amount' => Amount::class,
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
