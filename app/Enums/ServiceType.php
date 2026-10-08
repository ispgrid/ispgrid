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
}
