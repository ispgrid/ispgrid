<?php

namespace App\Billing;

/**
 * What staff chose for the money side of a recharge: a discount for this recharge (null: the customer's saved one),
 * its optional reason and whether to keep it as the customer's discount, the payment taken now, or an invoice already
 * issued for it (an advance renewal invoiced at booking).
 */
final readonly class ChargeOptions
{
    public function __construct(
        public ?Discount $discount = null,
        public ?string $discountReason = null,
        public bool $keepDiscount = false,
        public ?PaymentInput $payment = null,
        public ?int $existingInvoiceId = null,
    ) {}
}
