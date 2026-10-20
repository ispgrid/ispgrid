<?php

namespace App\Billing\India;

/**
 * The shape of an Indian GSTIN: state code, PAN, entity number, Z, check character.
 */
final class Gstin
{
    public static function isValid(string $gstin): bool
    {
        return preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin) === 1
            && GstStates::has(self::stateCode($gstin));
    }

    public static function stateCode(string $gstin): string
    {
        return substr($gstin, 0, 2);
    }
}
