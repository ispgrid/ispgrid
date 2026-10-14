<?php

namespace App\Notifications;

use App\Enums\NotificationChannel;
use App\Models\Account;
use App\Models\AppSetting;

/**
 * Customer numbers are stored as staff typed them. Providers need E.164 (+919876543210).
 */
final class PhoneNumber
{
    private const E164 = '/^\+[1-9][0-9]{6,14}$/';

    /**
     * @return ?string E.164, or null when the number cannot be read
     */
    public static function toE164(?string $raw, string $countryCode, int $digits): ?string
    {
        $clean = preg_replace('/[\s\-().]/', '', (string) $raw) ?? '';

        if ($clean === '') {
            return null;
        }

        if (str_starts_with($clean, '00')) {
            $clean = '+'.substr($clean, 2);
        }

        if (str_starts_with($clean, '+')) {
            return preg_match(self::E164, $clean) === 1 ? $clean : null;
        }

        if (preg_match('/^[0-9]+$/', $clean) !== 1) {
            return null;
        }

        $code = ltrim($countryCode, '+');
        $national = ltrim($clean, '0');

        if (strlen($national) === $digits) {
            return '+'.$code.$national;
        }

        if (strlen($clean) === strlen($code) + $digits && str_starts_with($clean, $code)) {
            return '+'.$clean;
        }

        return null;
    }

    public static function fromSettings(?string $raw): ?string
    {
        return self::toE164($raw, (string) AppSetting::valueOf('phone_country_code', '+91'), (int) AppSetting::valueOf('phone_digits', '10'));
    }

    /**
     * The number a channel uses: WhatsApp prefers the WhatsApp number, SMS uses the mobile.
     */
    public static function rawFor(Account $account, NotificationChannel $channel): ?string
    {
        if ($channel === NotificationChannel::WhatsApp && ($account->whatsapp ?? '') !== '') {
            return $account->whatsapp;
        }

        return $account->mobile;
    }
}
