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
}
