<?php

namespace App\Enums;

/**
 * The outcome of a login attempt in radpostauth.
 */
enum LoginResult: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Dropped = 'dropped';

    /**
     * isp logs a request it dropped (no answer sent, Blast-RADIUS checks) with reply '0'.
     */
    public static function fromReply(string $reply): self
    {
        return match ($reply) {
            'Access-Accept' => self::Accepted,
            '0' => self::Dropped,
            default => self::Rejected,
        };
    }

    /**
     * The radpostauth.reply value of this result.
     */
    public function reply(): string
    {
        return match ($this) {
            self::Accepted => 'Access-Accept',
            self::Rejected => 'Access-Reject',
            self::Dropped => '0',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Accepted => __('Accepted'),
            self::Rejected => __('Rejected'),
            self::Dropped => __('Dropped'),
        };
    }

    /**
     * The x-ui.badge colour for this result.
     */
    public function color(): string
    {
        return match ($this) {
            self::Accepted => 'green',
            self::Rejected => 'red',
            self::Dropped => 'amber',
        };
    }
}
