<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Cancel recharge was refused. $why says what stands in the way: 'not-latest' (a newer recharge exists, or this one
 * is cancelled or a plan switch), 'payments' (its invoice has payments), 'advance-waiting' (an advance renewal waits),
 * 'too-old' (recorded before cancelling was possible, so the old plan and status are unknown) or 'plan-deleted' (the plan
 * before it was deleted since).
 */
class CannotCancelRecharge extends RuntimeException
{
    public function __construct(public readonly string $why)
    {
        parent::__construct("The recharge cannot be cancelled: {$why}.");
    }
}
