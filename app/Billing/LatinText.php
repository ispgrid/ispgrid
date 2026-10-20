<?php

namespace App\Billing;

/**
 * Whether text can print on the invoice, whose font has Latin letters only (English, and accented letters).
 */
final class LatinText
{
    public static function isLatin(?string $text): bool
    {
        if ($text === null || $text === '') {
            return true;
        }

        return preg_match('/\p{L}/u', (string) preg_replace('/\p{Latin}/u', '', $text)) === 0;
    }
}
