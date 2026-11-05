<?php

namespace App\Support;

use App\Actions\Customers\SetSelfcarePassword;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Notifications\Notifier;
use App\Notifications\Triggers\SelfcareLogin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
     * @param  Builder<Account>  $query
     */
    public static function scopeEligible(Builder $query): void
    {
        $query->whereIn('service_type', self::SERVICE_TYPES)->whereIn('status_id', self::STATUSES);
    }

    public static function url(): string
    {
        return url('/selfcare');
    }

    /**
     * Sets a new random password and sends it by every enabled "Selfcare login" rule (SMS and WhatsApp get the same
     * one). False, with nothing changed, while Selfcare is off or no such rule can send.
     */
    public static function sendLogin(Account $account): bool
    {
        if (! self::isOpen() || ! self::canSendLogin()) {
            return false;
        }

        DB::transaction(function () use ($account): void {
            $password = app(SetSelfcarePassword::class)->handle($account);
            app(Notifier::class)->fire(SelfcareLogin::key(), $account, secret: $password);
        }, attempts: 3);

        return true;
    }

    /**
     * Whether an enabled "Selfcare login" rule has an enabled provider.
     */
    public static function canSendLogin(): bool
    {
        return NotificationRule::query()->where('enabled', true)->where('trigger', SelfcareLogin::key())
            ->whereIn('provider', NotificationProvider::enabledKeys())->exists();
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
            __('Choose your own password when you first log in.'),
        ]);
    }
}
