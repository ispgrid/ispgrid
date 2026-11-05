<?php

namespace App\Support;

use App\Enums\ServiceType;
use App\Models\Account;

/**
 * What the customer needs for their own router. A PPPoE router gets everything from its session, so only a static
 * IP is worth showing; an IPoE router is set up by hand: IP, mask, gateway and DNS from the customer's IP range.
 */
final class SelfcareConnection
{
    /**
     * @return array{ip: ?string, mask: ?string, gateway: ?string, dns1: ?string, dns2: ?string}
     */
    public static function for(Account $account): array
    {
        $ip = $account->ip;
        $range = $account->service_type === ServiceType::Ipoe ? $ip?->range : null;

        return [
            'ip' => $ip?->address,
            'mask' => $range === null ? null : Ipv4Cidr::parse($range->network)?->netmask(),
            'gateway' => $range?->gateway,
            'dns1' => $range?->dns1,
            'dns2' => $range?->dns2,
        ];
    }
}
