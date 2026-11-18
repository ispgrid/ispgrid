<?php

namespace App\Support\Setup;

use NumberFormatter;

/**
 * The settings a country starts with on the setup page: currency (from the intl extension's region data), number
 * grouping, date format, phone numbers and the customer ID labels. Every value stays editable on the page and later
 * in App settings and Billing settings.
 */
final readonly class CountryDefaults
{
    /**
     * Phone country code, shortest and longest national number (without the country code or the leading 0), date
     * format, tax ID label and business ID label, for the countries ISPGrid knows. Other countries get the currency
     * only, and the phone code is typed on the page.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: string, 4: string, 5: string}>
     */
    private const COUNTRIES = [
        'IN' => ['+91', 10, 10, 'd/m/Y', 'GSTIN', 'PAN'],
        'NP' => ['+977', 10, 10, 'd/m/Y', 'PAN', ''],
        'BD' => ['+880', 10, 10, 'd/m/Y', 'BIN', 'TIN'],
        'PK' => ['+92', 10, 10, 'd/m/Y', 'NTN', ''],
        'LK' => ['+94', 9, 9, 'd/m/Y', 'VAT number', ''],
        'AE' => ['+971', 9, 9, 'd/m/Y', 'TRN', ''],
        'SA' => ['+966', 9, 9, 'd/m/Y', 'VAT number', ''],
        'QA' => ['+974', 8, 8, 'd/m/Y', 'Tax ID', ''],
        'OM' => ['+968', 8, 8, 'd/m/Y', 'VAT number', ''],
        'KW' => ['+965', 8, 8, 'd/m/Y', 'Tax ID', ''],
        'BH' => ['+973', 8, 8, 'd/m/Y', 'VAT number', ''],
        'US' => ['+1', 10, 10, 'm/d/Y', 'Tax ID', ''],
        'CA' => ['+1', 10, 10, 'Y-m-d', 'GST/HST number', ''],
        'GB' => ['+44', 10, 10, 'd/m/Y', 'VAT number', 'Company number'],
        'IE' => ['+353', 9, 9, 'd/m/Y', 'VAT number', ''],
        'AU' => ['+61', 9, 9, 'd/m/Y', 'ABN', ''],
        'NZ' => ['+64', 8, 10, 'd/m/Y', 'GST number', ''],
        'DE' => ['+49', 10, 11, 'd.m.Y', 'VAT number', ''],
        'FR' => ['+33', 9, 9, 'd/m/Y', 'VAT number', ''],
        'IT' => ['+39', 9, 10, 'd/m/Y', 'VAT number', ''],
        'ES' => ['+34', 9, 9, 'd/m/Y', 'VAT number', ''],
        'NL' => ['+31', 9, 9, 'd-m-Y', 'VAT number', ''],
        'ZA' => ['+27', 9, 9, 'Y-m-d', 'VAT number', ''],
        'NG' => ['+234', 10, 10, 'd/m/Y', 'TIN', ''],
        'KE' => ['+254', 9, 9, 'd/m/Y', 'KRA PIN', ''],
        'GH' => ['+233', 9, 9, 'd/m/Y', 'TIN', ''],
        'UG' => ['+256', 9, 9, 'd/m/Y', 'TIN', ''],
        'TZ' => ['+255', 9, 9, 'd/m/Y', 'TIN', ''],
        'EG' => ['+20', 10, 10, 'd/m/Y', 'Tax ID', ''],
        'ID' => ['+62', 9, 12, 'd/m/Y', 'NPWP', ''],
        'MY' => ['+60', 9, 10, 'd/m/Y', 'SST number', ''],
        'SG' => ['+65', 8, 8, 'd/m/Y', 'GST number', ''],
        'PH' => ['+63', 10, 10, 'm/d/Y', 'TIN', ''],
        'TH' => ['+66', 9, 9, 'd/m/Y', 'Tax ID', ''],
        'VN' => ['+84', 9, 10, 'd/m/Y', 'Tax ID', ''],
        'TR' => ['+90', 10, 10, 'd.m.Y', 'Tax ID', ''],
        'BR' => ['+55', 10, 11, 'd/m/Y', 'CNPJ', 'CPF'],
        'MX' => ['+52', 10, 10, 'd/m/Y', 'RFC', ''],
    ];

    /** Countries that group digits in lakhs and crores (12,34,567). */
    private const INDIAN_GROUPING = ['IN', 'NP', 'BD'];

    public function __construct(
        public string $country,
        public string $currencyCode,
        public string $currencySymbol,
        public int $currencyDecimals,
        public string $numberGrouping,
        public string $dateFormat,
        public string $phoneCode,
        public int $phoneMin,
        public int $phoneMax,
        public string $taxIdLabel,
        public string $businessIdLabel,
    ) {}

    public static function for(string $country): self
    {
        $country = strtoupper($country);
        [$phoneCode, $phoneMin, $phoneMax, $dateFormat, $taxIdLabel, $businessIdLabel] = self::COUNTRIES[$country] ?? ['', 10, 10, 'd/m/Y', 'Tax ID', ''];
        $currency = new NumberFormatter('en_'.$country, NumberFormatter::CURRENCY);

        return new self(
            country: $country,
            currencyCode: (string) $currency->getTextAttribute(NumberFormatter::CURRENCY_CODE),
            currencySymbol: (string) $currency->getSymbol(NumberFormatter::CURRENCY_SYMBOL),
            currencyDecimals: max(0, min(3, (int) $currency->getAttribute(NumberFormatter::FRACTION_DIGITS))),
            numberGrouping: in_array($country, self::INDIAN_GROUPING, true) ? 'indian' : 'international',
            dateFormat: $dateFormat,
            phoneCode: $phoneCode,
            phoneMin: $phoneMin,
            phoneMax: $phoneMax,
            taxIdLabel: $taxIdLabel,
            businessIdLabel: $businessIdLabel,
        );
    }

    /**
     * Whether ISPGrid knows this country's phone numbers (else the page asks for the code).
     */
    public static function knows(string $country): bool
    {
        return isset(self::COUNTRIES[strtoupper($country)]);
    }
}
