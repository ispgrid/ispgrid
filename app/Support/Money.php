<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Formats an amount the way the app settings say: currency symbol, decimals, Indian (12,34,567) or
 * international (1,234,567) grouping. One setting read per page, not per amount.
 */
final class Money
{
    public function __construct(
        private string $symbol,
        private int $decimals,
        private string $grouping,
    ) {}

    public static function fromSettings(): self
    {
        return new self(
            (string) AppSetting::valueOf('currency_symbol', ''),
            (int) AppSetting::valueOf('currency_decimals', '2'),
            (string) AppSetting::valueOf('number_grouping', 'international'),
        );
    }

    public function format(string $amount): string
    {
        $fixed = number_format(abs((float) $amount), $this->decimals, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $fixed, 2), 2, null);

        if ($this->grouping === 'indian' && strlen($whole) > 3) {
            $whole = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)).','.substr($whole, -3);
        } else {
            $whole = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
        }

        return ((float) $amount < 0 ? '-' : '').$this->symbol.$whole.($fraction === null ? '' : '.'.$fraction);
    }
}
