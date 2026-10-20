<?php

namespace App\Billing\India;

/**
 * Indian states and union territories by GST state code (the first two digits of a GSTIN). The codes stay
 * two-digit strings: PHP would turn "10" and up into integer array keys.
 */
final class GstStates
{
    private const STATES = [
        ['01', 'Jammu and Kashmir'], ['02', 'Himachal Pradesh'], ['03', 'Punjab'], ['04', 'Chandigarh'],
        ['05', 'Uttarakhand'], ['06', 'Haryana'], ['07', 'Delhi'], ['08', 'Rajasthan'], ['09', 'Uttar Pradesh'],
        ['10', 'Bihar'], ['11', 'Sikkim'], ['12', 'Arunachal Pradesh'], ['13', 'Nagaland'], ['14', 'Manipur'],
        ['15', 'Mizoram'], ['16', 'Tripura'], ['17', 'Meghalaya'], ['18', 'Assam'], ['19', 'West Bengal'],
        ['20', 'Jharkhand'], ['21', 'Odisha'], ['22', 'Chhattisgarh'], ['23', 'Madhya Pradesh'], ['24', 'Gujarat'],
        ['26', 'Dadra and Nagar Haveli and Daman and Diu'], ['27', 'Maharashtra'], ['29', 'Karnataka'], ['30', 'Goa'],
        ['31', 'Lakshadweep'], ['32', 'Kerala'], ['33', 'Tamil Nadu'], ['34', 'Puducherry'],
        ['35', 'Andaman and Nicobar Islands'], ['36', 'Telangana'], ['37', 'Andhra Pradesh'], ['38', 'Ladakh'],
        ['97', 'Other Territory'],
    ];

    /**
     * @return list<array{code: string, name: string}> in code order, for a select
     */
    public static function options(): array
    {
        return array_map(fn (array $state): array => ['code' => $state[0], 'name' => $state[1]], self::STATES);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::STATES, 0);
    }

    public static function has(?string $code): bool
    {
        return self::name($code) !== null;
    }

    public static function name(?string $code): ?string
    {
        foreach (self::STATES as [$stateCode, $name]) {
            if ($stateCode === $code) {
                return $name;
            }
        }

        return null;
    }

    /**
     * "Delhi (07)", or the code alone when it is not a state code.
     */
    public static function label(string $code): string
    {
        $name = self::name($code);

        return $name === null ? $code : $name.' ('.$code.')';
    }
}
