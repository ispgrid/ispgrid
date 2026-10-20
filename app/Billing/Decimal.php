<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * Money arithmetic on decimal strings with bcmath, never floats. Every input is checked to be a number, so a
 * stray value fails loudly instead of counting as zero.
 */
final class Decimal
{
    /**
     * Rounds half up (away from zero for negatives): 8.905 to 8.91, -0.825 to -0.83.
     *
     * @return numeric-string
     */
    public static function round(string $value, int $places): string
    {
        $number = self::number($value);
        $negative = str_starts_with($number, '-');
        $half = $places > 0 ? '0.'.str_repeat('0', $places).'5' : '0.5';
        $rounded = bcadd(self::number(ltrim($number, '-+')), self::number($half), $places);

        return $negative && bccomp($rounded, '0', $places) !== 0 ? self::number('-'.$rounded) : $rounded;
    }

    /**
     * The percentage of an amount, unrounded (scale 4).
     *
     * @return numeric-string
     */
    public static function percentOf(string $amount, string $percent): string
    {
        return bcdiv(bcmul(self::number($amount), self::number($percent), 8), '100', 4);
    }

    /**
     * @return numeric-string
     */
    public static function add(string $a, string $b, int $scale = 4): string
    {
        return bcadd(self::number($a), self::number($b), $scale);
    }

    /**
     * @return numeric-string
     */
    public static function sub(string $a, string $b, int $scale = 4): string
    {
        return bcsub(self::number($a), self::number($b), $scale);
    }

    /**
     * @return numeric-string
     */
    public static function mul(string $a, string $b, int $scale = 4): string
    {
        return bcmul(self::number($a), self::number($b), $scale);
    }

    /**
     * @return numeric-string
     */
    public static function div(string $a, string $b, int $scale = 4): string
    {
        return bcdiv(self::number($a), self::number($b), $scale);
    }

    /**
     * -1, 0 or 1, like the spaceship operator.
     */
    public static function compare(string $a, string $b, int $scale = 4): int
    {
        return bccomp(self::number($a), self::number($b), $scale);
    }

    /**
     * A rate without trailing zeros, for labels: 9.000 to 9, 2.500 to 2.5.
     */
    public static function trim(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /**
     * @return numeric-string
     */
    private static function number(string $value): string
    {
        $value = trim($value);

        if (! is_numeric($value) || str_contains(strtolower($value), 'e')) {
            throw new InvalidArgumentException("Not a decimal number: [{$value}].");
        }

        return $value;
    }
}
