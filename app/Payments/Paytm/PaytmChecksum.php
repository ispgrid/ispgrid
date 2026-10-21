<?php

namespace App\Payments\Paytm;

/**
 * Paytm's request and callback signature (their paytmchecksum library, rewritten so core needs no package): the
 * SHA-256 of the text, a pipe and a 4-character salt, followed by the salt, AES-128-CBC encrypted with the merchant
 * key and Paytm's fixed IV, in base64. API calls sign the JSON body; callbacks sign their fields sorted by name and
 * joined with pipes.
 */
final class PaytmChecksum
{
    private const IV = '@@@@&&&&####$$$$';

    public static function sign(string $text, string $key): string
    {
        $salt = substr(bin2hex(random_bytes(2)), 0, 4);

        return (string) openssl_encrypt(hash('sha256', $text.'|'.$salt).$salt, 'AES-128-CBC', html_entity_decode($key), 0, self::IV);
    }

    public static function verify(string $text, string $key, string $signature): bool
    {
        $decrypted = openssl_decrypt($signature, 'AES-128-CBC', html_entity_decode($key), 0, self::IV);

        if (! is_string($decrypted) || strlen($decrypted) !== 68) {
            return false;
        }

        $salt = substr($decrypted, -4);

        return hash_equals($decrypted, hash('sha256', $text.'|'.$salt).$salt);
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    public static function signFields(array $fields, string $key): string
    {
        return self::sign(self::joined($fields), $key);
    }

    /**
     * @param  array<string, string|null>  $fields  the posted fields without CHECKSUMHASH
     */
    public static function verifyFields(array $fields, string $key, string $signature): bool
    {
        return self::verify(self::joined($fields), $key, $signature);
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    private static function joined(array $fields): string
    {
        ksort($fields, SORT_STRING);

        return implode('|', array_map(
            fn (?string $value): string => $value === null || strtolower($value) === 'null' ? '' : $value,
            $fields,
        ));
    }
}
