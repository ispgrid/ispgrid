<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Queued'),
            self::Sent => __('Sent'),
            self::Failed => __('Failed'),
            self::Skipped => __('Skipped'),
        };
    }

    /**
     * The x-ui.badge colour for this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Queued => 'zinc',
            self::Sent => 'green',
            self::Failed => 'red',
            self::Skipped => 'amber',
        };
    }
}
