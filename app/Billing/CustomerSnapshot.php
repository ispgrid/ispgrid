<?php

namespace App\Billing;

use App\Billing\India\GstStates;
use App\Models\Account;
use App\Models\Address;

/**
 * The customer as an invoice prints them, copied at issue: the billing name and address (the connection address
 * unless a separate one is set), the connection address, the tax number and the place of supply.
 */
final class CustomerSnapshot
{
    /**
     * @return array{username: string, name: string, billing_lines: list<string>, connectivity_lines: list<string>, tax_number: ?string, place_of_supply: ?string, place_label: ?string}
     */
    public static function of(Account $account, BillingSettings $settings): array
    {
        $taxNumber = trim((string) $account->tax_id) === '' ? null : trim((string) $account->tax_id);
        $name = self::firstFilled([$account->billing_name, $account->company, $account->fullname, $account->username]);
        $connection = self::addressLines($account->address, $settings);
        $billing = $account->billingAddress === null ? $connection : self::addressLines($account->billingAddress, $settings);
        $place = self::placeOfSupply($account, $taxNumber, $settings);

        return [
            'username' => $account->username,
            'name' => $name,
            'billing_lines' => array_merge([$name], $billing),
            'connectivity_lines' => $connection,
            'tax_number' => $taxNumber,
            'place_of_supply' => $place,
            'place_label' => $place === null ? null : GstStates::label($place),
        ];
    }

    /**
     * Where the sale is supplied, for a tax setup that uses it: the customer's own place when they have a tax
     * number (a business registered elsewhere), otherwise the company's state.
     */
    private static function placeOfSupply(Account $account, ?string $taxNumber, BillingSettings $settings): ?string
    {
        if (! $settings->tax()->usesPlaceOfSupply()) {
            return null;
        }

        $home = ($settings->taxConfig['state'] ?? '') === '' ? null : $settings->taxConfig['state'];

        return $taxNumber !== null && GstStates::has($account->place_of_supply) ? $account->place_of_supply : $home;
    }

    /**
     * @return list<string>
     */
    private static function addressLines(?Address $address, BillingSettings $settings): array
    {
        if ($address === null) {
            return [];
        }

        $country = trim((string) $address->country);
        $home = Countries::name($settings->company->country);

        return array_values(array_filter(array_map('trim', [
            (string) $address->line1,
            (string) $address->line2,
            (string) $address->area,
            trim($address->city.' '.$address->postal_code),
            (string) $address->state,
            strcasecmp($country, $home) === 0 || strcasecmp($country, $settings->company->country) === 0 ? '' : $country,
        ]), fn (string $line): bool => $line !== ''));
    }

    /**
     * @param  list<?string>  $values
     */
    private static function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }
}
