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
     * @return list<array{label: string, value: string, href: ?string, hint: ?string}>
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

        if (! $settings->enabled) {
            return [
                ['label' => __('Sales today'), 'value' => $money->format(self::sales($today)), 'href' => null, 'hint' => null],
                ['label' => __('Sales this month'), 'value' => $money->format(self::sales($month)), 'href' => null, 'hint' => null],
                ['label' => __('Sales last month'), 'value' => $money->format(self::sales($lastMonth, $month)), 'href' => null, 'hint' => null],
            ];
        }

        $cards = [
            ['label' => __('Collected today'), 'value' => $money->format(self::sum(self::payments($today))), 'href' => null, 'hint' => null],
            ['label' => __('Collected this month'), 'value' => $money->format(self::sum(self::payments($month))), 'href' => null, 'hint' => self::byMethod($month, $money)],
            ['label' => __('Collected last month'), 'value' => $money->format(self::sum(self::payments($lastMonth, $month))), 'href' => null, 'hint' => null],
            ['label' => __('Invoiced this month'), 'value' => $money->format(self::sum(self::invoices()->where('issue_date', '>=', $month->toDateString()), 'total')), 'href' => null, 'hint' => null],
            ['label' => __('Invoiced last month'), 'value' => $money->format(self::sum(self::invoices()->where('issue_date', '>=', $lastMonth->toDateString())->where('issue_date', '<', $month->toDateString()), 'total')), 'href' => null, 'hint' => null],
            ['label' => __('Dues'), 'value' => $money->format(self::sum(self::invoices(), 'balance')),
                'href' => $user->can('viewAny', Account::class) ? route('customers.index', ['dues' => 1]) : null, 'hint' => null],
        ];

        if (! $settings->invoiceHotspot) {
            $hotspot = self::recharges($month)->whereNull('invoice_id')
                ->whereHas('account', fn (Builder $account) => $account->withTrashed()->where('service_type', ServiceType::Hotspot));

            if ($hotspot->exists()) {
                $cards[] = ['label' => __('Hotspot sales this month'), 'value' => $money->format(self::sum($hotspot)), 'href' => null, 'hint' => null];
            }
        }

        return $cards;
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
            'amount' => $money->format(Decimal::round((string) $row->sold, 2)),
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
        return Recharge::query()->where('created_at', '>=', $since)->when($until, fn (Builder $query) => $query->where('created_at', '<', $until))
            ->whereHas('account', fn (Builder $account) => $account->withTrashed()->where('billing_free', false));
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
            ->mapWithKeys(fn (object $row): array => [(string) ($names[$row->payment_method_id] ?? '') => $money->format(Decimal::round((string) $row->received, 2))])
            ->sortKeys();

        return $sums->isEmpty() ? null : $sums->map(fn (string $amount, string $method): string => "{$method} {$amount}")->implode(', ');
    }

    /**
     * @param  Builder<Payment>|Builder<Invoice>|Builder<Recharge>  $query
     */
    private static function sum(Builder $query, string $column = 'amount'): string
    {
        return Decimal::round((string) ($query->sum($column) ?: '0'), 2);
    }
}
