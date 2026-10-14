<?php

namespace App\Notifications;

/**
 * What a provider says about one message. A provider maps every error of its API to failed(); it throws only when
 * it could not reach the API at all, and the job treats that as retryable.
 */
final readonly class SendResult
{
    private function __construct(
        public bool $sent,
        public string $providerMessageId,
        public string $reason,
        public bool $retryable,
        public ?int $retryAfterSeconds,
    ) {}

    public static function sent(string $providerMessageId): self
    {
        return new self(true, $providerMessageId, '', false, null);
    }

    public static function failed(string $reason, bool $retryable = false, ?int $retryAfterSeconds = null): self
    {
        return new self(false, '', $reason, $retryable, $retryAfterSeconds);
    }
}
