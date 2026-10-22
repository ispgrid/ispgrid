<?php

namespace App\Casts;

use App\Billing\Decimal;
use App\Support\Currency;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A money column: a decimal string with the currency's decimals ("1499.00", "12.375" for dinars), where
 * decimal:2 would cut a dinar amount to fils/10.
 *
 * @implements CastsAttributes<numeric-string|null, string|int|float|null>
 */
final class Amount implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Decimal::round((string) $value, Currency::decimals());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Decimal::round((string) $value, Currency::decimals());
    }
}
