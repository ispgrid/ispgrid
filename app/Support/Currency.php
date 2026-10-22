<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * The currency's number of decimals (App settings): 2 for rupees and dollars, 3 for dinars and rials (1000 fils),
 * 0 for yen. Amounts are stored with 3 decimals and rounded, checked and shown with this many. Read once per
 * request or queued job, and again after the setting is saved.
 */
final readonly class Currency
{
    /** The most decimals any amount column holds. */
    public const MAX_DECIMALS = 3;

    /** The largest amount a form accepts: ten whole digits. */
    public const MAX_AMOUNT = '9999999999.999';

    public function __construct(public int $decimals) {}

    public static function fromSettings(): self
    {
        return new self(max(0, min(self::MAX_DECIMALS, (int) AppSetting::valueOf('currency_decimals', '2'))));
    }

    public static function decimals(): int
    {
        return app(self::class)->decimals;
    }

    /**
     * A typed amount: up to ten whole digits and the currency's decimals ("1499", "1499.5", "12.375" for dinars).
     */
    public static function amountPattern(): string
    {
        $decimals = self::decimals();

        return $decimals === 0 ? '/^\d{1,10}$/' : '/^\d{1,10}(\.\d{1,'.$decimals.'})?$/';
    }

    /**
     * Validation for a typed amount of 0 or more.
     *
     * @return list<string>
     */
    public static function amountRules(): array
    {
        return ['numeric', 'decimal:0,'.self::decimals(), 'min:0', 'max:'.self::MAX_AMOUNT];
    }

    /**
     * The step of a number input: 0.01, 0.001 or 1.
     */
    public static function step(): string
    {
        $decimals = self::decimals();

        return $decimals === 0 ? '1' : '0.'.str_repeat('0', $decimals - 1).'1';
    }
}
