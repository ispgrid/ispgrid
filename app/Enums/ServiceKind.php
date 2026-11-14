<?php

namespace App\Enums;

/**
 * What a service item does besides billing: nothing (plain), or a fixed IP the customer gets while it is paid.
 */
enum ServiceKind: string
{
    case Plain = 'plain';
    case StaticIp = 'static_ip';

    public function label(): string
    {
        return match ($this) {
            self::Plain => __('Plain (billing only)'),
            self::StaticIp => __('Static IP'),
        };
    }
}
