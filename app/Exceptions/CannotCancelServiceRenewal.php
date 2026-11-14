<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Cancel renewal of a customer service was refused. $why: 'not-latest' (a newer renewal exists, this one is
 * cancelled, or the service ended), 'first' (the row Add service wrote: end the service and cancel its invoice
 * instead) or 'payments' (its invoice has payments).
 */
class CannotCancelServiceRenewal extends RuntimeException
{
    public function __construct(public readonly string $why)
    {
        parent::__construct("The service renewal cannot be cancelled: {$why}.");
    }
}
