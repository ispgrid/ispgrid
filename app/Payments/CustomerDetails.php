<?php

namespace App\Payments;

use App\Billing\BillingSettings;
use App\Models\Account;
use App\Models\AppSetting;
use App\Notifications\PhoneNumber;

/**
 * What a gateway may send about the customer, taken from their account and never invented: the name (full name,
 * else username), the email (the customer's, else the ISP's Billing company email) and the mobile in E.164.
 * A mobile counts only when it is a real one: for +91 that is 10 digits starting with 6 to 9, so "12345" and
 * landline numbers are no mobile.
 */
final readonly class CustomerDetails
{
    public function __construct(public string $name, public ?string $email, public ?string $phone) {}

    public static function for(Account $account): self
    {
        $name = trim((string) $account->fullname);
        $email = trim((string) $account->email);
        $companyEmail = BillingSettings::current()->company->email;

        return new self(
            $name !== '' ? $name : (string) $account->username,
            $email !== '' ? $email : ($companyEmail !== '' ? $companyEmail : null),
            self::usablePhone($account->mobile),
        );
    }

    /**
     * The mobile without the country code (10 digits for India), or null when there is none.
     */
    public function nationalPhone(): ?string
    {
        if ($this->phone === null) {
            return null;
        }

        return substr($this->phone, strlen(self::countryCode()) + 1);
    }

    private static function usablePhone(?string $raw): ?string
    {
        $phone = PhoneNumber::fromSettings($raw);

        if ($phone === null) {
            return null;
        }

        if (self::countryCode() === '91' && preg_match('/^\+91[6-9][0-9]{9}$/', $phone) !== 1) {
            return null;
        }

        return $phone;
    }

    private static function countryCode(): string
    {
        return ltrim((string) AppSetting::valueOf('phone_country_code', '+91'), '+');
    }
}
