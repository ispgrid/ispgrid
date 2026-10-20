<?php

namespace App\Billing;

use ResourceBundle;

/**
 * Countries by ISO 3166 alpha-2 code with their English names, from the intl extension's region data.
 */
final class Countries
{
    /**
     * Region codes in the data that are not countries (EU, UN, private use, ...).
     */
    private const NOT_COUNTRIES = ['AC', 'CP', 'CQ', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'XK', 'ZZ'];

    /**
     * @return array<string, string> sorted by name
     */
    public static function all(): array
    {
        static $countries = null;

        if ($countries === null) {
            $countries = [];
            $regions = ResourceBundle::create('en', 'ICUDATA-region');

            foreach ($regions instanceof ResourceBundle ? $regions->get('Countries') : [] as $code => $name) {
                if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1 && ! in_array($code, self::NOT_COUNTRIES, true)) {
                    $countries[$code] = (string) $name;
                }
            }

            asort($countries);
        }

        return $countries;
    }

    public static function name(string $code): string
    {
        return self::all()[$code] ?? $code;
    }
}
