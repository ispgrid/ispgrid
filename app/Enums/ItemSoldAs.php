<?php

namespace App\Enums;

/**
 * How an item is sold: once on an invoice line, or as a service the customer keeps paying for on its own clock.
 */
enum ItemSoldAs: string
{
    case OneTime = 'one_time';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::OneTime => __('One-time'),
            self::Service => __('Service'),
        };
    }
}
