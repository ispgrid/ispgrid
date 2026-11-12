<?php

namespace App\Billing;

use App\Models\Account;
use App\Support\Money;

/**
 * A discount on a recharge: a percent of the price or a fixed amount off it. A fixed amount never takes more than
 * the line is worth, so a total cannot go below 0. Tax is worked out on what is left.
 */
final readonly class Discount
{
    public const PERCENT = 'percent';

    public const AMOUNT = 'amount';

    /**
     * @param  string  $type  self::PERCENT or self::AMOUNT
     * @param  string  $value  the percent (10 for 10%) or the amount
     */
    public function __construct(public string $type, public string $value) {}

    public static function none(): self
    {
        return new self(self::PERCENT, '0');
    }

    /**
     * The customer's saved discount.
     */
    public static function forAccount(Account $account): self
    {
        return $account->discount_type === self::AMOUNT
            ? new self(self::AMOUNT, (string) $account->discount_amount)
            : new self(self::PERCENT, (string) $account->discount_percent);
    }

    public function isAmount(): bool
    {
        return $this->type === self::AMOUNT;
    }

    public function isNone(): bool
    {
        return ! is_numeric($this->value) || Decimal::compare($this->value, '0') <= 0;
    }

    /**
     * What it takes off a line of this amount, rounded to the currency's decimals and never above the line.
     */
    public function takenOff(string $lineAmount, int $decimals): string
    {
        if ($this->isNone()) {
            return Decimal::round('0', $decimals);
        }

        $taken = Decimal::round($this->isAmount() ? $this->value : Decimal::percentOf($lineAmount, $this->value), $decimals);

        return Decimal::compare($taken, $lineAmount) > 0 ? Decimal::round($lineAmount, $decimals) : $taken;
    }

    /**
     * "10%" or the formatted amount.
     */
    public function describe(Money $money): string
    {
        return $this->isAmount() ? $money->format($this->value) : Decimal::trim($this->value).'%';
    }
}
