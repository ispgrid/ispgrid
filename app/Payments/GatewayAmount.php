<?php

namespace App\Payments;

use App\Billing\Decimal;
use App\Exceptions\GatewayUnavailable;
use InvalidArgumentException;
use ValueError;

/**
 * Amount conversions for gateways, on decimal strings (never floats). Amounts come from our own database, so a
 * negative one or one with more than two decimals is a bug and refuses the payment. Nothing is ever rounded.
 */
final class GatewayAmount
{
    /**
     * The amount in minor units (paise, cents), or whole units for a zero-decimal currency.
     *
     * @throws GatewayUnavailable
     */
    public static function minor(string $amount, bool $zeroDecimal = false): int
    {
        $value = self::checked($amount);

        if ($zeroDecimal) {
            if (! str_ends_with($value, '.00')) {
                throw new GatewayUnavailable('The amount has a fraction but the currency has no minor unit.');
            }

            return (int) substr($value, 0, -3);
        }

        return (int) str_replace('.', '', $value);
    }

    /**
     * The amount for a minor-unit figure from a provider (whole units for a zero-decimal currency), with two decimals.
     *
     * @throws GatewayUnavailable
     */
    public static function fromMinor(int|string $minor, bool $zeroDecimal = false): string
    {
        $minor = (string) $minor;

        if (preg_match('/^\d+$/', $minor) !== 1) {
            throw new GatewayUnavailable('The gateway sent an amount that is not a whole number of minor units.');
        }

        try {
            return $zeroDecimal ? Decimal::add($minor, '0', 2) : Decimal::div($minor, '100', 2);
        } catch (InvalidArgumentException|ValueError) {
            throw new GatewayUnavailable('The gateway sent an amount that is not a number.');
        }
    }

    /**
     * The amount with exactly two decimals: 708.5 to 708.50.
     *
     * @throws GatewayUnavailable
     */
    public static function twoDecimals(string $amount): string
    {
        return self::checked($amount);
    }

    /**
     * A plain non-negative decimal with at most two fractional digits ("10", "10.", ".5", " 10 " are fine; signs,
     * exponents, hex and a third decimal are not), as a string with exactly two decimals.
     *
     * @return numeric-string
     *
     * @throws GatewayUnavailable
     */
    private static function checked(string $amount): string
    {
        $amount = trim($amount);

        if (preg_match('/^(\d+)?(?:\.(\d{0,2}))?$/', $amount, $parts) !== 1 || ($parts[1] ?? '') === '' && ($parts[2] ?? '') === '') {
            throw new GatewayUnavailable('The amount is not a plain non-negative number with at most two decimals.');
        }

        $whole = ltrim($parts[1] ?? '', '0');
        $fraction = str_pad($parts[2] ?? '', 2, '0');

        try {
            return Decimal::add(($whole === '' ? '0' : $whole).'.'.$fraction, '0', 2);
        } catch (InvalidArgumentException|ValueError) {
            throw new GatewayUnavailable('The amount is not a number.');
        }
    }
}
