<?php

namespace App\Billing;

/**
 * What staff chose for the money side of a recharge: a one-off price instead of the plan price (with its reason),
 * the payment taken now, or an invoice already issued for it (an advance renewal invoiced at booking).
 */
final readonly class ChargeOptions
{
    public function __construct(
        public ?string $adjustedPrice = null,
        public ?string $adjustReason = null,
        public ?PaymentInput $payment = null,
        public ?int $existingInvoiceId = null,
    ) {}
}
