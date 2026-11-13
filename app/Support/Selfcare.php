<?php

namespace App\Support;

use App\Actions\Customers\SetSelfcarePassword;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\SelfcareAccount;
use App\Notifications\Notifier;
use App\Notifications\Triggers\SelfcareLogin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The customer portal at /selfcare. Off on a new install (selfcare.enabled); while off, its pages are not found and
 * the customer page hides the selfcare panel. PPPoE and IPoE customers that are not Terminated or deleted may log in
 * with a selfcare password staff gave them.
 */
final class Selfcare
{
    /** @var list<ServiceType> */
    public const SERVICE_TYPES = [ServiceType::Ppp, ServiceType::Ipoe];

    /** @var list<AccountStatus> */
    public const STATUSES = [AccountStatus::Active, AccountStatus::Inactive, AccountStatus::Suspended];

    public static function isOpen(): bool
    {
        return AppSetting::valueOf('selfcare.enabled', '0') === '1';
    }

    /**
     * Whether customers may change their own PPPoE password (a separate switch: a wrong change takes them offline).
     */
    public static function canChangePppoePassword(): bool
    {
        return self::isOpen() && AppSetting::valueOf('selfcare.pppoe_password_change', '0') === '1';
    }

    /**
     * Whether this customer may use selfcare, leaving aside whether a selfcare password is set.
     */
    public static function eligible(Account $account): bool
    {
        return ! $account->trashed()
            && in_array($account->service_type, self::SERVICE_TYPES, true)
            && in_array($account->status_id, self::STATUSES, true);
    }

    /**
     * The same rule as eligible(), as a query constraint (soft-deleted rows are already left out by the model).
     *
     * @template TModel of Account
     *
     * @param  Builder<TModel>  $query
     */
    public static function scopeEligible(Builder $query): void
    {
        $query->whereIn('service_type', self::SERVICE_TYPES)->whereIn('status_id', self::STATUSES);
    }

    /**
     * The mobile number a customer typed at the login, as the last digits customers are compared on: the last 10, or
     * the panel's phone number length when shorter, so a country code, spaces or a leading 0 do not matter. Null when
     * the text is not a phone number (other characters than digits, spaces, + and -, or too few digits).
     */
    public static function mobileKey(string $typed): ?string
    {
        if (preg_match('/^\+?[0-9][0-9 \-]*$/D', trim($typed)) !== 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $typed);
        $length = min(10, max(4, (int) AppSetting::valueOf('phone_digits', '10')));

        return strlen($digits) >= $length ? substr($digits, -$length) : null;
    }

    /**
     * The customers who may use Selfcare and whose mobile number ends with this key (mobileKey()), oldest first.
     *
     * @return Builder<SelfcareAccount>
     */
    public static function connectionsOnMobile(string $key): Builder
    {
        return SelfcareAccount::query()->where(fn (Builder $query) => self::scopeEligible($query))
            ->whereNotNull('mobile')->whereRaw('RIGHT(mobile, ?) = ?', [strlen($key), $key])->orderBy('id');
    }

    /**
     * Whether this customer's mobile number still ends with the key a mobile login used.
     */
    public static function hasMobile(Account $account, string $key): bool
    {
        return $key !== '' && substr((string) $account->mobile, -strlen($key)) === $key;
    }

    public static function url(): string
    {
        return url('/selfcare');
    }

    /**
     * Checks the selfcare password a logged-in customer types again (password change, PPPoE password): 5 wrong tries
     * a minute per customer, and a right one clears the count.
     *
     * @throws ValidationException
     */
    public static function confirmPassword(Account $account, string $given, string $field, string $wrongMessage): void
    {
        $key = 'selfcare-confirm|'.$account->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([$field => __('Too many tries. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
        }

        if (! Hash::check($given, (string) $account->selfcare_password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([$field => $wrongMessage]);
        }

        RateLimiter::clear($key);
    }

    /**
     * Sets a new random password and sends it by every enabled "Selfcare login" rule (SMS and WhatsApp get the same
     * one). False, with nothing changed, while Selfcare is off, the customer may not use it, or no such rule can send
     * to this customer.
     */
    public static function sendLogin(Account $account): bool
    {
        if (! self::isOpen() || ! self::eligible($account) || ! self::canSendLogin($account)) {
            return false;
        }

        DB::transaction(function () use ($account): void {
            $password = app(SetSelfcarePassword::class)->handle($account);
            app(Notifier::class)->fire(SelfcareLogin::key(), $account, secret: $password);
        }, attempts: 3);

        return true;
    }

    /**
     * Whether an enabled "Selfcare login" rule with an enabled provider takes this customer (its plan, service type
     * and status filters), so a new password is never set without a message to carry it.
     */
    public static function canSendLogin(Account $account): bool
    {
        return app(Notifier::class)->rulesFor(SelfcareLogin::key(), $account)->isNotEmpty();
    }

    /**
     * The login to hand to the customer, as one message staff copy or read out.
     */
    public static function details(Account $account, string $password): string
    {
        return implode("\n", [
            __('Selfcare: :url', ['url' => self::url()]),
            __('Username: :username', ['username' => $account->username]),
            __('Password: :password', ['password' => $password]),
            ...($account->selfcare_must_change ? [__('Choose your own password when you first log in.')] : []),
        ]);
    }
}
