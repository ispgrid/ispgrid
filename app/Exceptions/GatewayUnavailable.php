<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A payment gateway refused or could not be reached. The message is for the log, never for the customer.
 * $httpStatus is the HTTP status the provider answered with, or null when it was never reached or sent no answer.
 */
final class GatewayUnavailable extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
