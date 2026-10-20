<?php

namespace App\Enums;

/**
 * Where an invoice came from: a recharge (any kind) or a manual invoice written by staff, such as for a CPE.
 */
enum InvoiceKind: string
{
    case Recharge = 'recharge';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Recharge => __('Recharge'),
            self::Manual => __('Manual'),
        };
    }
}
