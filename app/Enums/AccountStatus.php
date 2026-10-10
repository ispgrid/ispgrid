<?php

namespace App\Enums;

enum AccountStatus: int
{
    case Active = 1;
    case Inactive = 2;
    case Suspended = 3;
    case Terminated = 4;

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Inactive => __('Inactive'),
            self::Suspended => __('Suspended'),
            self::Terminated => __('Terminated'),
        };
    }

    /**
     * The x-ui.badge colour for this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => 'zinc',
            self::Suspended => 'amber',
            self::Terminated => 'red',
        };
    }
}
