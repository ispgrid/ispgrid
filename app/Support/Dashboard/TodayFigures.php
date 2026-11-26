<?php

namespace App\Support\Dashboard;

use App\Enums\AccountStatus;
use App\Enums\RechargeKind;
use App\Models\Account;
use App\Models\Radacct;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's numbers of the day. Each card opens the customers list with the filters that count it, when the
 * staff member can open that list. Expiring and expired count Active customers only.
 */
final class TodayFigures
{
    /**
     * Each card carries its group (attention, customers or renewals), its tone (green, amber, red or neutral; a warning
     * at 0 is neutral), an icon and, where the panel keeps history, a short trend with its caption. Cards come out in the
     * order each group shows them; Needs attention runs from the most urgent (expired, expiring, disabled).
     *
     * @return list<array{label: string, value: int, href: ?string, group: string, tone: string, icon: string, trend: ?list<float>, caption: ?string}>
     */
    public static function cards(User $user): array
    {
        $customers = $user->can('viewAny', Account::class);
        $active = AccountStatus::Active->value;
        $link = fn (array $filters): ?string => $customers ? route('customers.index', $filters) : null;

        $expiringWeek = fn (): Builder => self::active()->whereBetween('expiry', [now(), now()->addDays(7)]);
        $week = now()->subDays(6);

        $disabled = Account::query()->whereIn('status_id', [AccountStatus::Inactive, AccountStatus::Suspended])->count();
        $expiringToday = self::active()->whereBetween('expiry', [now(), now()->endOfDay()])->count();
        $expiring = $expiringWeek()->count();
        $withoutRenewal = $expiringWeek()->where('auto_renew', false)->doesntHave('waitingRenewal')->count();
        $expired = self::active()->where('expiry', '>=', now()->subDays(7))->where('expiry', '<', now())->count();
        $staticIp = $expiringWeek()->whereNotNull('ip_id')->count();

        return [
            self::card(__('Expired in the last 7 days'), $expired, $link(['status' => $active, 'expired' => 7]), 'attention', self::warning($expired, 'red'), 'exclamation-triangle'),
            self::card(__('Expiring within 7 days'), $expiring, $link(['status' => $active, 'expiring' => 1]), 'attention', self::warning($expiring, 'amber'), 'clock'),
            self::card(__('Disabled'), $disabled, $link(['disabled' => 1]), 'attention', self::warning($disabled, 'red'), 'no-symbol'),
            self::card(__('Total'), Account::query()->count(), $link([]), 'customers', 'neutral', 'users'),
            self::card(__('Online now'), Radacct::query()->open()->distinct()->count('username'),
                $user->can('view-online-sessions') ? route('online.index') : null, 'customers', 'green', 'wifi',
                Trends::perDay(DB::table(config('ispgrid.usage_database').'.usage_daily'), 'day', $week, 'COUNT(DISTINCT username)'), __('last 7 days')),
            self::card(__('Active'), self::active()->count(), $link(['status' => $active]), 'customers', 'green', 'check-circle'),
            self::card(__('Expiring today'), $expiringToday, $link(['status' => $active, 'expiring' => 'today']), 'renewals', self::warning($expiringToday, 'amber'), 'clock'),
            self::card(__('Without a renewal'), $withoutRenewal, $link(['status' => $active, 'expiring' => 1, 'norenewal' => 1]), 'renewals', self::warning($withoutRenewal, 'amber'), 'arrow-path'),
            self::card(__('Renewed today'), Account::query()->whereExists(fn ($query) => $query->select(DB::raw(1))->from('recharges')
                ->whereColumn('recharges.account_id', 'accounts.id')->where('recharges.created_at', '>=', now()->startOfDay())
                ->whereNull('recharges.cancelled_at')->where('recharges.kind', '!=', RechargeKind::SwitchPlan->value))->count(),
                $link(['renewed' => 1]), 'renewals', 'green', 'arrow-path',
                Trends::perDay(Recharge::query()->counted()->whereHas('account'), 'created_at', $week, 'COUNT(DISTINCT account_id)'), __('last 7 days')),
            self::card(__('New today'), Account::query()->where('created_at', '>=', now()->startOfDay())->count(),
                $link(['created' => 'today']), 'customers', 'neutral', 'user-plus'),
            self::card(__('New this month'), Account::query()->where('created_at', '>=', now()->startOfMonth())->count(),
                $link(['created' => 'month']), 'customers', 'green', 'user-plus',
                Trends::perDay(Account::query(), 'created_at', now()->startOfMonth()), __('per day')),
            self::card(__('Static IP expiring within 7 days'), $staticIp, $link(['status' => $active, 'expiring' => 1, 'staticip' => 1]),
                'renewals', self::warning($staticIp, 'amber'), 'globe-alt'),
        ];
    }

    /**
     * @param  ?list<float>  $trend
     * @return array{label: string, value: int, href: ?string, group: string, tone: string, icon: string, trend: ?list<float>, caption: ?string}
     */
    private static function card(string $label, int $value, ?string $href, string $group, string $tone, string $icon, ?array $trend = null, ?string $caption = null): array
    {
        return ['label' => $label, 'value' => $value, 'href' => $href, 'group' => $group, 'tone' => $tone, 'icon' => $icon, 'trend' => $trend, 'caption' => $caption];
    }

    /**
     * A warning card takes its color only when there is something to warn about.
     */
    private static function warning(int $value, string $tone): string
    {
        return $value > 0 ? $tone : 'neutral';
    }

    /**
     * @return Builder<Account>
     */
    private static function active(): Builder
    {
        return Account::query()->where('status_id', AccountStatus::Active);
    }
}
