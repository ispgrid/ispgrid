<?php

namespace App\Payments;

use Carbon\CarbonImmutable;

/**
 * A renewal of the customer's current plan offered on the pay page: a recharge from now when the plan has expired,
 * an advance renewal from the current expiry when it has not.
 */
final readonly class RenewalOffer
{
    public const RECHARGE = 'recharge';

    public const ADVANCE = 'advance';

    /**
     * @param  self::RECHARGE|self::ADVANCE  $kind
     */
    public function __construct(
        public string $kind,
        public int $planId,
        public string $price,
        public CarbonImmutable $expiry,
    ) {}
}
