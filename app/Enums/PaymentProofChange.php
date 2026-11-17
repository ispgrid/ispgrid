<?php

namespace App\Enums;

/**
 * What happened to a payment's screenshot, as the activity log stores it (the values) and staff read it (the labels).
 */
enum PaymentProofChange: string
{
    case Added = 'added';
    case Replaced = 'replaced';
    case Removed = 'removed';
    case OnFile = 'on file';

    public function label(): string
    {
        return match ($this) {
            self::Added => __('Screenshot added'),
            self::Replaced => __('Screenshot replaced'),
            self::Removed => __('Screenshot removed'),
            self::OnFile => __('On file'),
        };
    }
}
