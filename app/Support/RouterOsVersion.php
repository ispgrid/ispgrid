<?php

namespace App\Support;

/**
 * A RouterOS release number such as 7.23.7. RouterOS 7.15 and 6.49.18 were the first to send
 * Message-Authenticator in RADIUS requests (Blast-RADIUS, CVE-2024-3596); isp drops requests without it
 * from routers marked nas.require_message_auth = 1.
 */
final class RouterOsVersion
{
    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
    ) {}

    public static function parse(string $value): ?self
    {
        if (preg_match('/^\s*(\d{1,2})\.(\d{1,3})(?:\.(\d{1,3}))?\s*$/', $value, $matches) !== 1) {
            return null;
        }

        return new self((int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0));
    }

    public function sendsMessageAuthenticator(): bool
    {
        return match (true) {
            $this->major >= 8 => true,
            $this->major === 7 => $this->minor >= 15,
            $this->major === 6 => [$this->minor, $this->patch] >= [49, 18],
            default => false,
        };
    }

    /**
     * The warning for routers too old to send Message-Authenticator (spec section 5); null for newer or
     * unknown versions.
     */
    public static function warningFor(?string $version): ?string
    {
        $parsed = $version === null ? null : self::parse($version);

        if ($parsed === null || $parsed->sendsMessageAuthenticator()) {
            return null;
        }

        return __("RouterOS :version is affected by the Blast-RADIUS security issue (CVE-2024-3596). Upgrade to RouterOS 7, which is the best option. If you're not ready for v7, upgrade at least to 6.49.18 (long-term).", ['version' => trim($version)]);
    }
}
