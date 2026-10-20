<?php

namespace App\Support\Dashboard;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Radacct;
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
     * @return list<array{label: string, value: int, href: ?string}>
     */
    public static function cards(User $user): array
    {
        $customers = $user->can('viewAny', Account::class);
        $active = AccountStatus::Active->value;
        $link = fn (array $filters): ?string => $customers ? route('customers.index', $filters) : null;

        $expiringWeek = fn (): Builder => self::active()->whereBetween('expiry', [now(), now()->addDays(7)]);

        return [
            ['label' => __('Total'), 'value' => Account::query()->count(), 'href' => $link([])],
            ['label' => __('Online now'), 'value' => Radacct::query()->open()->distinct()->count('username'),
                'href' => $user->can('view-online-sessions') ? route('online.index') : null],
            ['label' => __('Active'), 'value' => self::active()->count(), 'href' => $link(['status' => $active])],
            ['label' => __('Disabled'), 'value' => Account::query()->whereIn('status_id', [AccountStatus::Inactive, AccountStatus::Suspended])->count(),
                'href' => $link(['disabled' => 1])],
            ['label' => __('Expiring today'), 'value' => self::active()->whereBetween('expiry', [now(), now()->endOfDay()])->count(),
                'href' => $link(['status' => $active, 'expiring' => 'today'])],
            ['label' => __('Expiring within 7 days'), 'value' => $expiringWeek()->count(),
                'href' => $link(['status' => $active, 'expiring' => 1])],
            ['label' => __('Without a renewal'), 'value' => $expiringWeek()->where('auto_renew', false)->doesntHave('waitingRenewal')->count(),
                'href' => $link(['status' => $active, 'expiring' => 1, 'norenewal' => 1])],
            ['label' => __('Expired in the last 7 days'), 'value' => self::active()->where('expiry', '>=', now()->subDays(7))->where('expiry', '<', now())->count(),
                'href' => $link(['status' => $active, 'expired' => 7])],
            ['label' => __('Renewed today'), 'value' => Account::query()->whereExists(fn ($query) => $query->select(DB::raw(1))->from('recharges')
                ->whereColumn('recharges.account_id', 'accounts.id')->where('recharges.created_at', '>=', now()->startOfDay()))->count(),
                'href' => $link(['renewed' => 1])],
            ['label' => __('New today'), 'value' => Account::query()->where('created_at', '>=', now()->startOfDay())->count(),
                'href' => $link(['created' => 'today'])],
            ['label' => __('New this month'), 'value' => Account::query()->where('created_at', '>=', now()->startOfMonth())->count(),
                'href' => $link(['created' => 'month'])],
            ['label' => __('Static IP expiring within 7 days'), 'value' => $expiringWeek()->whereNotNull('ip_id')->count(),
                'href' => $link(['status' => $active, 'expiring' => 1, 'staticip' => 1])],
        ];
    }

    /**
     * @return Builder<Account>
     */
    private static function active(): Builder
    {
        return Account::query()->where('status_id', AccountStatus::Active);
    }
}
