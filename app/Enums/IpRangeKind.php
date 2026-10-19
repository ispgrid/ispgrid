<?php

namespace App\Enums;

/**
 * What an IP range is for: a subnet on a router port (IPoE), or addresses routed to PPPoE sessions as /32s.
 */
enum IpRangeKind: string
{
    case Ipoe = 'ipoe';
    case Pppoe = 'pppoe';

    public function label(): string
    {
        return match ($this) {
            self::Ipoe => __('IPoE'),
            self::Pppoe => __('PPPoE'),
        };
    }
}
