<?php

namespace App\Actions\Customers;

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Gives the customer a new selfcare password from staff: typed, or 10 random characters that are easy to read out
 * (no 0, O, 1, I or l). Only the hash is kept; the customer must replace it at their next login. Any Selfcare session
 * that logged in with the old one ends on its next request.
 */
final class SetSelfcarePassword
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    /**
     * @return string the plain password, to show or send once
     */
    public function handle(Account $account, ?string $password = null): string
    {
        $password ??= self::random();

        DB::transaction(function () use ($account, $password): void {
            $account->forceFill([
                'selfcare_password' => Hash::make($password),
                'selfcare_must_change' => true,
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
