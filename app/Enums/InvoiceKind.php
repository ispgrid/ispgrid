<?php

namespace App\Enums;

/**
 * Where an invoice came from: a recharge (any kind), a manual invoice written by staff (such as for a CPE), or a
 * customer service (a static IP) added or renewed.
 */
enum InvoiceKind: string
{
    case Recharge = 'recharge';
    case Manual = 'manual';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Recharge => __('Recharge'),
            self::Manual => __('Manual'),
            self::Service => __('Service'),
        };
    }
}
