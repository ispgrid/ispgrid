<?php

namespace App\Billing;

use Carbon\CarbonInterface;

/**
 * Money received: how, how much, the bank or UPI reference, and when. Online is set only by the settlement of an
 * online payment, the one place a gateway's own method may be used.
 */
final readonly class PaymentInput
{
    public function __construct(
        public int $methodId,
        public string $amount,
        public ?string $reference,
        public CarbonInterface $paidAt,
        public ?string $note,
        public bool $online = false,
    ) {}
}
