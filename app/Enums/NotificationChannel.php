<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Sms => __('SMS'),
            self::WhatsApp => __('WhatsApp'),
        };
    }
}
