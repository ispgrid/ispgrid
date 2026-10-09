<?php

namespace App\Support;

/**
 * The canonical text form of an IP address, so one address is never stored two ways
 * ("2001:DB8::1" and "2001:db8:0:0:0:0:0:1").
 */
final class IpAddress
{
    public static function normalize(string $value): ?string
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);

        return $packed === false ? null : (inet_ntop($packed) ?: null);
    }
}
