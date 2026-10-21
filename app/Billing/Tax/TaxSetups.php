<?php

namespace App\Billing\Tax;

use InvalidArgumentException;

/**
 * The tax setups the panel knows, in the order the settings page offers them.
 */
final class TaxSetups
{
    /**
     * @param  list<TaxSetup>  $setups
     */
    public function __construct(private readonly array $setups) {}

    /**
     * The setups offered for a company in this country.
     *
     * @return list<TaxSetup>
     */
    public function forCountry(?string $country): array
    {
        return array_values(array_filter(
            $this->setups,
            fn (TaxSetup $setup): bool => $setup->countries() === null || in_array($country, $setup->countries(), true),
        ));
    }

    public function get(string $id): TaxSetup
    {
        foreach ($this->setups as $setup) {
            if ($setup->id() === $id) {
                return $setup;
            }
        }

        throw new InvalidArgumentException("Unknown tax setup [{$id}].");
    }
}
