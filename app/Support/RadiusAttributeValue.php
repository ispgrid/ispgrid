<?php

namespace App\Support;

use App\Models\RadiusAttribute;

/**
 * Checks a custom attribute value against its allowlist type before it reaches radgroupreply or radreply.
 * FreeRADIUS rejects the customer's login when a stored value does not parse.
 */
final class RadiusAttributeValue
{
    public static function error(RadiusAttribute $attribute, string $value): ?string
    {
        return match ($attribute->type) {
            'integer' => preg_match('/^(0|[1-9][0-9]{0,9})$/', $value) === 1 && (int) $value <= 4_294_967_295
                ? null : __('Enter a whole number.'),
            'ipv4' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? null : __('Enter an IPv4 address, e.g. 255.255.255.0.'),
            'ipv4-prefix' => self::isPrefix($value, FILTER_FLAG_IPV4, 32)
                ? null : __('Enter an IPv4 prefix, e.g. 10.0.0.0/24.'),
            'ipv6-prefix' => self::isPrefix($value, FILTER_FLAG_IPV6, 128)
                ? null : __('Enter an IPv6 prefix, e.g. 2001:db8::/48.'),
            'enum' => in_array($value, $attribute->allowed_values ?? [], true)
                ? null : __('Choose one of: :values.', ['values' => implode(', ', $attribute->allowed_values ?? [])]),
            default => preg_match('/^[\x20-\x7E]{1,253}$/', $value) === 1 && strpbrk($value, '"\'\\`') === false
                ? null : __('Use plain text without quotes or backslashes, up to 253 characters.'),
        };
    }

    private static function isPrefix(string $value, int $family, int $maxLength): bool
    {
        if (preg_match('/^([^\/]+)\/(0|[1-9][0-9]{0,2})$/', $value, $match) !== 1) {
            return false;
        }

        return filter_var($match[1], FILTER_VALIDATE_IP, $family) !== false && (int) $match[2] <= $maxLength;
    }
}
