<?php

namespace App\Payments;

/**
 * The short code of a customer's pay link (/p/1234-a8k2mq): the account id and six characters signed with the app
 * key, so a code cannot be guessed from another. Short enough for one DLT variable (the approved template holds the
 * address); it never changes unless the app key does.
 */
final class PayCode
{
    private const CHECK_LENGTH = 6;

    public static function for(int $accountId): string
    {
        return $accountId.'-'.self::check($accountId);
    }

    /**
     * The account id of a valid code, or null.
     */
    public static function accountId(string $code): ?int
    {
        if (preg_match('/^([1-9][0-9]{0,9})-([0-9a-z]{'.self::CHECK_LENGTH.'})$/', strtolower(trim($code)), $match) !== 1) {
            return null;
        }

        $accountId = (int) $match[1];

        return hash_equals(self::check($accountId), $match[2]) ? $accountId : null;
    }

    private static function check(int $accountId): string
    {
        $hash = hash_hmac('sha256', 'pay-code|'.$accountId, (string) config('app.key'));

        return str_pad(substr(base_convert(substr($hash, 0, 12), 16, 36), -self::CHECK_LENGTH), self::CHECK_LENGTH, '0', STR_PAD_LEFT);
    }
}
