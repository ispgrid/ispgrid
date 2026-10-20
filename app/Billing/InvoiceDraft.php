<?php

namespace App\Billing;

use App\Billing\Tax\TaxLine;

/**
 * The amounts of an invoice before it is issued, as the calculator worked them out.
 */
final readonly class InvoiceDraft
{
    /**
     * @param  list<DraftLine>  $lines
     * @param  list<TaxLine>  $taxLines
     */
    public function __construct(
        public array $lines,
        public array $taxLines,
        public string $taxableTotal,
        public string $taxTotal,
        public string $roundOff,
        public string $total,
    ) {}
}
