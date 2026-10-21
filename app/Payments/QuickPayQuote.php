<?php

namespace App\Payments;

/**
 * What one customer can pay on the pay page: their dues, a renewal, and the total. The page shows only the masked
 * username and the amounts.
 */
final readonly class QuickPayQuote
{
    public function __construct(
        public int $accountId,
        public string $maskedUsername,
        public string $dues,
        public ?RenewalOffer $renewal,
        public string $total,
    ) {}
}
