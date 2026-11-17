<?php

namespace App\Payments;

use Carbon\CarbonInterface;

/**
 * A gateway's answer about one payment: its state, the amount it took (two decimals) and its own transaction id.
 * $currency is the ISO code the gateway took the money in; a gateway sets it, and a code other than the attempt's is
 * never recorded. $raw is kept on the attempt for staff, so a gateway leaves out signatures, tokens and keys.
 */
final readonly class GatewayResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public GatewayStatus $status,
        public ?string $amount = null,
        public ?string $txnId = null,
        public ?CarbonInterface $paidAt = null,
        public ?string $providerStatus = null,
        public array $raw = [],
        public ?string $currency = null,
    ) {}
}
