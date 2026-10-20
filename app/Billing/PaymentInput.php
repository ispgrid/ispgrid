<?php

namespace App\Billing;

use Carbon\CarbonInterface;

/**
 * Money received: how, how much, the bank or UPI reference, and when.
 */
final readonly class PaymentInput
{
    public function __construct(
        public int $methodId,
        public string $amount,
        public ?string $reference,
        public CarbonInterface $paidAt,
        public ?string $note,
    ) {}
}
