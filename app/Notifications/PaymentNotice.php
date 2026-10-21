<?php

namespace App\Notifications;

/**
 * What a message about a payment can say: the amount, its receipts and invoices, how it was paid and what is still
 * due. $key makes the message go out once (an online payment's public id).
 */
final readonly class PaymentNotice
{
    public function __construct(
        public string $key,
        public string $amount,
        public string $receipts,
        public string $invoices,
        public string $method,
        public string $balanceDue,
    ) {}
}
