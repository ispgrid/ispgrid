<?php

namespace App\Support\Dashboard;

use App\Billing\BillingSettings;
use App\Billing\Decimal;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Recharge;
use App\Models\User;
use App\Support\Currency;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard's money cards, for staff with View sales. With invoicing on: money collected (today, this month by
 * method, last month), invoiced this month and last month, and dues, plus Hotspot sales when Hotspot recharges are not
 * invoiced. With it off: sales from the recharge amounts. Cancelled invoices, removed payments and Free customers
 * never count. Also the top plans of the month by sales.
 */
final class MoneyFigures
{
    /**
     * Each card carries its group (money, or attention for Dues), its tone, an icon and, where the panel keeps history,
     * a short trend with its caption.
     *
     * @return list<array{label: string, value: string, href: ?string, hint: ?string, group: string, tone: string, icon: string, trend: ?list<float>, caption: ?string}>
     */
    public static function cards(User $user): array
    {
        if (! $user->can('view-sales')) {
            return [];
        }

        $money = Money::fromSettings();
        $settings = BillingSettings::current();
        $today = now()->startOfDay();
        $month = now()->startOfMonth();
        $lastMonth = $month->subMonthNoOverflow();
        $week = now()->subDays(6);

        if (! $settings->enabled) {
            return [
                self::card(__('Sales today'), $money->format(self::sales($today)), null, null, 'money', 'green', 'banknotes',
                    Trends::perDay(self::countedRecharges(), 'created_at', $week, 'SUM(amount)'), __('last 7 days')),
                self::card(__('Sales this month'), $money->format(self::sales($month)), null, null, 'money', 'green', 'banknotes',
                    Trends::running(Trends::perDay(self::countedRecharges(), 'created_at', $month, 'SUM(amount)')), __('this month')),
                self::card(__('Sales last month'), $money->format(self::sales($lastMonth, $month)), null, null, 'money', 'green', 'banknotes',
                    Trends::perMonth(self::countedRecharges(), 'created_at', 6, 'SUM(amount)'), __('6 months')),
            ];
        }

        $dues = self::sum(self::invoices(), 'balance');

        $cards = [
            self::card(__('Collected today'), $money->format(self::sum(self::payments($today))), null, null, 'money', 'green', 'banknotes',
                Trends::perDay(Payment::query(), 'paid_at', $week, 'SUM(amount)'), __('last 7 days')),
            self::card(__('Collected this month'), $money->format(self::sum(self::payments($month))), null, self::byMethod($month, $money), 'money', 'green', 'banknotes',
                Trends::running(Trends::perDay(Payment::query(), 'paid_at', $month, 'SUM(amount)')), __('this month')),
            self::card(__('Collected last month'), $money->format(self::sum(self::payments($lastMonth, $month))), null, null, 'money', 'green', 'banknotes',
                Trends::perMonth(Payment::query(), 'paid_at', 6, 'SUM(amount)'), __('6 months')),
            self::card(__('Invoiced this month'), $money->format(self::sum(self::invoices()->where('issue_date', '>=', $month->toDateString()), 'total')), null, null,
                'money', 'neutral', 'document-text', Trends::running(Trends::perDay(self::invoices(), 'issue_date', $month, 'SUM(total)')), __('this month')),
            self::card(__('Invoiced last month'), $money->format(self::sum(self::invoices()->where('issue_date', '>=', $lastMonth->toDateString())->where('issue_date', '<', $month->toDateString()), 'total')), null, null,
                'money', 'neutral', 'document-text', Trends::perMonth(self::invoices(), 'issue_date', 6, 'SUM(total)'), __('6 months')),
            self::card(__('Dues'), $money->format($dues), $user->can('viewAny', Account::class) ? route('customers.index', ['dues' => 1]) : null, null,
                'attention', Decimal::compare($dues, '0') > 0 ? 'red' : 'neutral', 'banknotes'),
        ];

        if (! $settings->invoiceHotspot) {
            $hotspot = self::recharges($month)->whereNull('invoice_id')
                ->whereHas('account', fn (Builder $account) => $account->withTrashed()->where('service_type', ServiceType::Hotspot));

            if ($hotspot->exists()) {
                $cards[] = self::card(__('Hotspot sales this month'), $money->format(self::sum($hotspot)), null, null, 'money', 'green', 'banknotes');
            }
        }

        return $cards;
    }

    /**
     * @param  ?list<float>  $trend
     * @return array{label: string, value: string, href: ?string, hint: ?string, group: string, tone: string, icon: string, trend: ?list<float>, caption: ?string}
     */
    private static function card(string $label, string $value, ?string $href, ?string $hint, string $group, string $tone, string $icon, ?array $trend = null, ?string $caption = null): array
    {
        return ['label' => $label, 'value' => $value, 'href' => $href, 'hint' => $hint, 'group' => $group, 'tone' => $tone, 'icon' => $icon, 'trend' => $trend, 'caption' => $caption];
    }

    /**
     * The 5 plans with the most sales this month (recharge amounts, Free customers left out), for View sales only.
     *
     * @return list<array{plan: string, recharges: int, amount: string}>
     */
    public static function topPlans(User $user): array
    {
        if (! $user->can('view-sales')) {
            return [];
        }

        $money = Money::fromSettings();
        $rows = self::recharges(now()->startOfMonth())->whereNotNull('plan_id')
            ->groupBy('plan_id')->selectRaw('plan_id, COUNT(*) AS recharges, SUM(amount) AS sold')
            ->orderByDesc('sold')->orderBy('plan_id')->limit(5)->toBase()->get();
        $names = Plan::withTrashed()->whereIn('id', $rows->pluck('plan_id'))->pluck('name', 'id');

        return array_values(array_map(fn (object $row): array => [
            'plan' => (string) ($names[$row->plan_id] ?? ''),
            'recharges' => (int) $row->recharges,
            'amount' => $money->format(Decimal::round((string) $row->sold, Currency::decimals())),
        ], $rows->all()));
    }

    /**
     * @return Builder<Payment>
     */
    private static function payments(CarbonInterface $since, ?CarbonInterface $until = null): Builder
    {
        return Payment::query()->where('paid_at', '>=', $since)->when($until, fn (Builder $query) => $query->where('paid_at', '<', $until));
    }

    /**
     * @return Builder<Invoice>
     */
    private static function invoices(): Builder
    {
        return Invoice::query()->where('status', '!=', InvoiceStatus::Cancelled);
    }

    /**
     * @return Builder<Recharge>
     */
    private static function recharges(CarbonInterface $since, ?CarbonInterface $until = null): Builder
    {
        return self::countedRecharges()->where('created_at', '>=', $since)->when($until, fn (Builder $query) => $query->where('created_at', '<', $until));
    }

    /**
     * The recharges that count as sales: not cancelled, not plan switches, not Free customers.
     *
     * @return Builder<Recharge>
     */
    private static function countedRecharges(): Builder
    {
        return Recharge::query()->counted()->whereHas('account', fn (Builder $account) => $account->withTrashed()->where('billing_free', false));
    }

    private static function sales(CarbonInterface $since, ?CarbonInterface $until = null): string
    {
        return self::sum(self::recharges($since, $until));
    }

    /**
     * "Cash 618.00, UPI 590.00": this month's payments per method.
     */
    private static function byMethod(CarbonInterface $since, Money $money): ?string
    {
        $names = PaymentMethod::query()->pluck('name', 'id');
        $sums = self::payments($since)->groupBy('payment_method_id')->selectRaw('payment_method_id, SUM(amount) AS received')->toBase()->get()
            ->mapWithKeys(fn (object $row): array => [(string) ($names[$row->payment_method_id] ?? '') => $money->format(Decimal::round((string) $row->received, Currency::decimals()))])
            ->sortKeys();

        return $sums->isEmpty() ? null : $sums->map(fn (string $amount, string $method): string => "{$method} {$amount}")->implode(', ');
    }

    /**
     * @param  Builder<Payment>|Builder<Invoice>|Builder<Recharge>  $query
     */
    private static function sum(Builder $query, string $column = 'amount'): string
    {
        return Decimal::round((string) ($query->sum($column) ?: '0'), Currency::decimals());
    }
}
