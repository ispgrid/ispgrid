<?php

namespace App\Support;

use App\Models\AppSetting;
use Closure;

/**
 * An optional pattern for new PPPoE and Selfcare passwords (App settings), off by default because such passwords
 * are easy to guess. Tokens: {first_name} (first word of the name, lowercase), {mobile_last4} and {digits} (4 random
 * digits), around fixed text. A name with non-English letters, a mobile with fewer than 4 digits, or a result under
 * 6 characters gives no password, and the caller uses a random one (FreeRADIUS values are English only).
 */
final class PasswordPattern
{
    public const SETTING = 'customers.password_pattern';

    public const TOKENS = ['{first_name}', '{mobile_last4}', '{digits}'];

    public const MIN_LENGTH = 6;

    public static function current(): string
    {
        return trim((string) AppSetting::valueOf(self::SETTING, ''));
    }

    public static function isOn(): bool
    {
        return self::current() !== '';
    }

    /**
     * The password the saved pattern makes for this customer, or null for a random one.
     */
    public static function make(?string $fullname, ?string $mobile): ?string
    {
        $pattern = self::current();

        if ($pattern === '') {
            return null;
        }

        $firstName = strtolower(explode(' ', trim((string) $fullname))[0]);
        $digits = (string) preg_replace('/\D/', '', (string) $mobile);
        $values = ['{first_name}' => $firstName, '{mobile_last4}' => substr($digits, -4), '{digits}' => sprintf('%04d', random_int(0, 9999))];

        if ((str_contains($pattern, '{first_name}') && preg_match('/^[a-z]+$/D', $firstName) !== 1)
            || (str_contains($pattern, '{mobile_last4}') && strlen($digits) < 4)) {
            return null;
        }

        $password = strtr($pattern, $values);

        return strlen($password) >= self::MIN_LENGTH && strlen($password) <= 64 && self::fixedTextFits($password) ? $password : null;
    }

    /**
     * The settings field: known tokens around printable English characters FreeRADIUS takes as they are.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:40', function (string $attribute, mixed $value, Closure $fail): void {
            $rest = str_replace(self::TOKENS, '', (string) $value);

            if (! self::fixedTextFits($rest) || str_contains($rest, '{') || str_contains($rest, '}')) {
                $fail(__('Use the tokens :tokens and letters, digits or symbols, without spaces, quotes or backslashes.', ['tokens' => implode(' ', self::TOKENS)]));
            }
        }];
    }

    /**
     * Printable English characters without spaces, quotes, backticks or backslashes (the customer password rule).
     */
    private static function fixedTextFits(string $text): bool
    {
        return $text === '' || (preg_match('/^[\x21-\x7E]+$/D', $text) === 1 && preg_match('/["\'`\\\\]/', $text) !== 1);
    }
}
