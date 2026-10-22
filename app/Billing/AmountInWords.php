<?php

namespace App\Billing;

/**
 * An amount in English words for the invoice ("Indian Rupee One Lakh Twenty Five Thousand Only"). Written here
 * because intl's spellout has no lakh and crore.
 */
final class AmountInWords
{
    /**
     * The whole and fraction names of common currencies.
     */
    private const CURRENCIES = [
        'INR' => ['Indian Rupee', 'Paise'],
        'USD' => ['US Dollar', 'Cents'],
        'EUR' => ['Euro', 'Cents'],
        'GBP' => ['Pound Sterling', 'Pence'],
        'AED' => ['UAE Dirham', 'Fils'],
        'KWD' => ['Kuwaiti Dinar', 'Fils'],
        'BHD' => ['Bahraini Dinar', 'Fils'],
        'OMR' => ['Omani Rial', 'Baisa'],
        'JOD' => ['Jordanian Dinar', 'Fils'],
    ];

    private const ONES = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /**
     * @param  int  $decimals  the currency's decimals: 2 gives "and Paise Fifty", 3 gives "and Fils Five Hundred"
     */
    public static function format(string $amount, string $currencyCode, bool $indianGrouping, int $decimals = 2): string
    {
        [$wholeName, $fractionName] = self::CURRENCIES[$currencyCode] ?? [$currencyCode, 'Cents'];
        [$whole, $fraction] = array_pad(explode('.', Decimal::round(ltrim($amount, '-'), $decimals), 2), 2, '0');

        $words = $wholeName.' '.self::words($whole, $indianGrouping);

        if ((int) $fraction > 0) {
            $words .= ' and '.$fractionName.' '.self::words($fraction, $indianGrouping);
        }

        return $words.' Only';
    }

    /**
     * A whole number of any size in words.
     */
    private static function words(string $number, bool $indianGrouping): string
    {
        $number = ltrim($number, '0');

        if ($number === '') {
            return self::ONES[0];
        }

        $scales = $indianGrouping
            ? [[7, 'Crore'], [5, 'Lakh'], [3, 'Thousand']]
            : [[9, 'Billion'], [6, 'Million'], [3, 'Thousand']];

        foreach ($scales as [$digits, $name]) {
            if (strlen($number) > $digits) {
                $high = substr($number, 0, -$digits);
                $rest = ltrim(substr($number, -$digits), '0');

                return trim(self::words($high, $indianGrouping).' '.$name.($rest === '' ? '' : ' '.self::words($rest, $indianGrouping)));
            }
        }

        return self::belowThousand((int) $number);
    }

    private static function belowThousand(int $number): string
    {
        $parts = [];

        if ($number >= 100) {
            $parts[] = self::ONES[intdiv($number, 100)].' Hundred';
            $number %= 100;
        }

        if ($number >= 20) {
            $parts[] = self::TENS[intdiv($number, 10)].($number % 10 > 0 ? ' '.self::ONES[$number % 10] : '');
        } elseif ($number > 0 || $parts === []) {
            $parts[] = self::ONES[$number];
        }

        return implode(' ', $parts);
    }
}
