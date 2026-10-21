<?php

namespace App\Billing;

/**
 * A worked-out invoice line: quantity times price, the discount, and the taxable value.
 */
final readonly class DraftLine
{
    public function __construct(
        public LineInput $input,
        public string $amount,
        public string $discountAmount,
        public string $taxable,
    ) {}
}
