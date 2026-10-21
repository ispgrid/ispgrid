<?php

namespace App\Payments;

/**
 * What a gateway says about a payment.
 */
enum GatewayStatus
{
    case Paid;
    case Failed;
    case Pending;
}
