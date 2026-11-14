<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Add service was refused. $why: 'not-service' (the item is not sold as a service, or is inactive), 'not-pppoe' (a
 * static IP needs a PPPoE customer), 'has-static-ip' (the customer already has a static IP service that is not
 * ended) or 'ip-invalid' (no address, not in the inventory, or given to someone else); $message then says why.
 */
class CannotAddService extends RuntimeException
{
    public function __construct(public readonly string $why, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : "The service cannot be added: {$why}.");
    }
}
