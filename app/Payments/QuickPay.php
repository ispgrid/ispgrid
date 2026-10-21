<?php

namespace App\Payments;

use App\Billing\BillingSettings;
use App\Billing\Decimal;
use App\Billing\InvoicePolicy;
use App\Billing\RechargePrice;
use App\Billing\Statement;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Notifications\PhoneNumber;
use App\Support\ExpiryCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who can pay what on the pay page. A username finds that customer; otherwise a mobile number finds every customer
 * with it. Deleted and Terminated customers are never found, and nobody with nothing to pay is listed, so the page
 * cannot tell an unknown name from a paid-up one.
 */
final class QuickPay
{
    /**
     * Whether the pay page is open: quick pay and invoicing on, and a gateway to pay with.
     */
    public static function isOpen(): bool
    {
        return AppSetting::valueOf('billing.quick_pay', '0') === '1'
            && BillingSettings::current()->canIssue()
            && app(PaymentGateways::class)->enabled() !== [];
    }

    /**
     * Days before the expiry from which the page offers a renewal, or null when it offers none.
     */
    public static function renewalDays(): ?int
    {
        $days = trim((string) AppSetting::valueOf('billing.quick_pay_renewal_days', '7'));

        return ctype_digit($days) ? min((int) $days, 60) : null;
    }

    /**
     * @return list<QuickPayQuote>
     */
    public function find(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            return [];
        }

        $accounts = Account::query()->where('username', $input)->where('status_id', '!=', AccountStatus::Terminated)->get();

        if ($accounts->isEmpty()) {
            $accounts = $this->byMobile($input);
        }

        $quotes = [];

        foreach ($accounts as $account) {
            $quote = $this->quote($account);

            if ($quote !== null) {
                $quotes[] = $quote;
            }
        }

        return $quotes;
    }

    /**
     * What this customer can pay now, or null when nothing.
     */
    public function quote(Account $account): ?QuickPayQuote
    {
        if ($account->trashed() || $account->status_id === AccountStatus::Terminated) {
            return null;
        }

        $dues = Statement::dues($account);
        $renewal = $this->renewal($account);
        $total = Decimal::round(Decimal::add($dues, $renewal->price ?? '0'), 2);

        if (Decimal::compare($total, '0') <= 0) {
            return null;
        }

        return new QuickPayQuote($account->id, self::mask((string) $account->username), $dues, $renewal, $total);
    }

    /**
     * ku***al: the first and last two characters with at least three stars between; a short name keeps only its
     * first character.
     */
    public static function mask(string $username): string
    {
        $length = mb_strlen($username);

        if ($length <= 4) {
            return mb_substr($username, 0, 1).'***';
        }

        return mb_substr($username, 0, 2).str_repeat('*', max(3, $length - 4)).mb_substr($username, -2);
    }

    /**
     * The customer's current plan, renewed: offered when the plan has expired or ends within the renewal days, to
     * an Active or Inactive customer who pays (not Free), whose renewal would be invoiced, with no advance renewal
     * already waiting. $anyTime leaves out the renewal days (settling a renewal the customer has already paid for).
     */
    public function renewal(Account $account, bool $anyTime = false): ?RenewalOffer
    {
        $days = self::renewalDays();
        $plan = $account->plan_id === null ? null : Plan::withTrashed()->find($account->plan_id);
        $now = CarbonImmutable::now();
        $settings = BillingSettings::current();

        if ((! $anyTime && $days === null) || $plan === null || $plan->trashed() || $plan->hotspot_starts_at_first_login || $account->expiry === null
            || ! in_array($account->status_id, [AccountStatus::Active, AccountStatus::Inactive], true)
            || $account->billing_free || ! InvoicePolicy::invoicesRecharge($account, $settings)
            || (! $anyTime && $account->expiry->greaterThan($now->addDays((int) $days))) || $account->waitingRenewal()->exists()) {
            return null;
        }

        $expired = $account->expiry->lessThanOrEqualTo($now);
        $current = CarbonImmutable::instance($account->expiry);
        $expiry = ExpiryCalculator::fromSettings()->next($current, $plan->validity, $plan->validity_unit, $expired ? $now : $current->subSecond());

        return new RenewalOffer($expired ? RenewalOffer::RECHARGE : RenewalOffer::ADVANCE, $plan->id, RechargePrice::amount($account, $plan, $settings), $expiry);
    }

    /**
     * Customers whose mobile is this number however either is written (spaces, +91, a leading 0): the last seven
     * digits narrow it down in SQL, the panel's phone settings decide.
     *
     * @return Collection<int, Account>
     */
    private function byMobile(string $input): Collection
    {
        $number = PhoneNumber::fromSettings($input);
        $digits = substr((string) preg_replace('/\D/', '', $input), -7);

        if ($number === null || strlen($digits) < 7) {
            return new Collection;
        }

        return Account::query()
            ->where('status_id', '!=', AccountStatus::Terminated)
            ->whereRaw("REGEXP_REPLACE(mobile, '[^0-9]', '') LIKE ?", ['%'.$digits])
            ->limit(200)
            ->get()
            ->filter(fn (Account $account): bool => PhoneNumber::fromSettings($account->mobile) === $number)
            ->values();
    }
}
