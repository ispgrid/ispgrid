<?php

namespace App\Billing;

/**
 * One invoice line as entered: a plan recharge or a manual item such as a CPE. Its discount is a percent, or a fixed
 * amount ($discountAmount, which then wins over the percent). $itemId is the catalog item the line was filled from.
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
        public ?string $discountAmount = null,
        public ?int $itemId = null,
    ) {}
}
