<?php

namespace App\Actions\Customers;

use App\Models\Account;
use App\Support\PasswordPattern;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Gives the customer a new selfcare password from staff: typed, one from the password pattern, or 10 random
 * characters that are easy to read out (no 0, O, 1, I or l). Only the hash is kept. The customer must replace a typed
 * or random one at their next login; a pattern one they may keep (the operator chose guessable passwords). Any
 * Selfcare session that logged in with the old one ends on its next request.
 */
final class SetSelfcarePassword
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    /**
     * @return string the plain password, to show or send once
     */
    public function handle(Account $account, ?string $password = null): string
    {
        $patterned = $password === null ? PasswordPattern::make($account->fullname, $account->mobile) : null;
        $password ??= $patterned ?? self::random();

        DB::transaction(function () use ($account, $password, $patterned): void {
            $account->forceFill([
                'selfcare_password' => Hash::make($password),
                'selfcare_must_change' => $patterned === null,
                'selfcare_password_set_at' => now(),
            ])->saveQuietly();

            activity()->performedOn($account)->event('selfcare-password-set')->log('selfcare-password-set');
        }, attempts: 3);

        return $password;
    }

    public static function random(): string
    {
        $characters = '';

        for ($i = 0; $i < 10; $i++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $characters;
    }
}
