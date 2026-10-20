<?php

namespace App\Billing;

/**
 * One invoice line as entered: a plan recharge or a manual item such as a CPE.
 */
final readonly class LineInput
{
    public function __construct(
        public string $description,
        public ?string $subDescription,
        public ?string $itemCode,
        public string $quantity,
        public string $unitPrice,
        public string $discountPercent,
        public bool $taxed,
    ) {}
}
