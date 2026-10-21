<?php

namespace App\Enums;

/**
 * Where a quick pay attempt stands. Paid and NeedsAttention are final: settling never touches them again.
 */
enum OnlinePaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case NeedsAttention = 'needs_attention';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Paid => __('Paid'),
            self::Failed => __('Failed'),
            self::NeedsAttention => __('Needs attention'),
        };
    }

    public function settled(): bool
    {
        return $this === self::Paid || $this === self::NeedsAttention;
    }
}
