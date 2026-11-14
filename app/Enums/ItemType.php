<?php

namespace App\Enums;

/**
 * What an item is: goods (a router, a cable) or a service. Under India GST goods carry an HSN code, services a SAC.
 */
enum ItemType: string
{
    case Goods = 'goods';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Goods => __('Goods'),
            self::Service => __('Service'),
        };
    }
}
