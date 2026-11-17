<?php

namespace App\Payments;

use App\Billing\Decimal;
use App\Exceptions\GatewayUnavailable;
use InvalidArgumentException;

/**
 * Amount conversions for gateways, on decimal strings (never floats). Amounts come from our own database, so a
 * negative one or one with more than two decimals is a bug and refuses the payment.
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
            if (Decimal::compare($value, Decimal::round($value, 0), 2) !== 0) {
                throw new GatewayUnavailable('The amount has a fraction but the currency has no minor unit.');
            }

            return (int) Decimal::round($value, 0);
        }

        return (int) Decimal::mul($value, '100', 0);
    }

    /**
     * The amount for a minor-unit figure from a provider, with two decimals.
     */
    public static function fromMinor(int|string $minor, bool $zeroDecimal = false): string
    {
        $minor = (string) $minor;

        try {
            return $zeroDecimal ? Decimal::add($minor, '0', 2) : Decimal::div($minor, '100', 2);
        } catch (InvalidArgumentException) {
            throw new GatewayUnavailable('The gateway sent an amount that is not a number.');
        }
    }

    /**
     * The amount with exactly two decimals: 708.5 to 708.50.
     */
    public static function twoDecimals(string $amount): string
    {
        return Decimal::add(self::checked($amount), '0', 2);
    }

    /**
     * @return numeric-string
     */
    private static function checked(string $amount): string
    {
        try {
            $value = Decimal::add($amount, '0', 4);
        } catch (InvalidArgumentException) {
            throw new GatewayUnavailable('The amount is not a number.');
        }

        if (Decimal::compare($value, '0', 4) < 0) {
            throw new GatewayUnavailable('The amount is negative.');
        }

        if (Decimal::compare($value, Decimal::round($value, 2), 4) !== 0) {
            throw new GatewayUnavailable('The amount has more than two decimals.');
        }

        return $value;
    }
}
