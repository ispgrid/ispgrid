<?php

namespace App\Payments;

use App\Models\OnlinePayment;

/**
 * A quick pay attempt just opened at the gateway, and where to send the customer.
 */
final readonly class StartedPayment
{
    public function __construct(
        public OnlinePayment $payment,
        public GatewayStart $start,
    ) {}
}
