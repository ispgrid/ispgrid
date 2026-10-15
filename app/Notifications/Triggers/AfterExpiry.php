<?php

namespace App\Notifications\Triggers;

use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Recharge;
use App\Notifications\Contracts\Trigger;
use App\Notifications\Placeholders;
use App\Notifications\SettingField;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers whose plan ended on the calendar day N days ago; 0 = earlier today.
 */
final class AfterExpiry implements Trigger
{
    public static function key(): string
    {
        return 'after_expiry';
    }

    public static function label(): string
    {
        return __('After expiry');
    }

    public static function settingFields(): array
    {
        return [new SettingField('days', __('Days after expiry'), 'number', help: __('0 sends on the day it expires.'), min: 0, max: 60)];
    }

    public static function placeholders(): array
    {
        return Placeholders::COMMON;
    }

    public static function scheduled(): bool
    {
        return true;
    }

    public static function summary(array $settings): string
    {
        $days = (int) ($settings['days'] ?? 0);

        return $days === 0
            ? __('On the day it expires')
            : trans_choice(':count day after expiry|:count days after expiry', $days);
    }

    public function due(array $settings, CarbonImmutable $now): Builder
    {
        $days = (int) ($settings['days'] ?? 0);
        $day = $now->subDays($days);

        if ($days === 0) {
            // A plan that ended after yesterday's last run in the sending hours is messaged this morning.
            $lastRun = $now->subDay()->setTimeFromTimeString((string) AppSetting::valueOf('notify_hours_to', '21:00'))->subMinutes(15);

            return Account::query()->where('expiry', '>', $lastRun)->where('expiry', '<=', $now);
        }

        return Account::query()
            ->whereBetween('expiry', [$day->startOfDay(), $day->endOfDay()])
            ->where('expiry', '<=', $now);
    }

    public function onceKey(int $ruleId, Account $account, ?Recharge $recharge): string
    {
        return "rule:{$ruleId}:account:{$account->id}:expiry:".$account->expiry?->format('Y-m-d H:i:s');
    }
}
