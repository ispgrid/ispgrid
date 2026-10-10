<?php

namespace App\Enums;

enum ServiceType: string
{
    case Ppp = 'PPP';
    case Hotspot = 'HOTSPOT';
    case Ipoe = 'IPOE';
    case Static = 'STATIC';

    /**
     * Whether accounts of this type are written to the FreeRADIUS tables.
     */
    public function usesRadius(): bool
    {
        return $this !== self::Static;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ppp => __('PPPoE'),
            self::Hotspot => __('Hotspot'),
            self::Ipoe => __('IPoE'),
            self::Static => __('Leased line'),
        };
    }
}
