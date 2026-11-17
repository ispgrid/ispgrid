<?php

namespace App\Contracts;

/**
 * Implemented by a payment gateway whose provider refuses a payment without the customer's mobile number or email
 * address. A customer lacking one is not offered the gateway; start() reads the values through
 * App\Payments\CustomerDetails::for($account).
 */
interface NeedsCustomerDetails
{
    /**
     * @return list<'phone'|'email'>
     */
    public static function requiredDetails(): array;
}
