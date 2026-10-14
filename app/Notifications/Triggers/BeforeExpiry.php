<?php

namespace App\Notifications\Triggers;

use App\Models\Account;
use App\Models\Recharge;
use App\Notifications\Contracts\Trigger;
use App\Notifications\Placeholders;
use App\Notifications\SettingField;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers whose plan ends on the calendar day N days from today (server timezone).
 */
final class BeforeExpiry implements Trigger
{
    public static function key(): string
    {
        return 'before_expiry';
    }

    public static function label(): string
    {
        return __('Before expiry');
    }

    public static function settingFields(): array
    {
        return [new SettingField('days', __('Days before expiry'), 'number', min: 1, max: 60)];
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
        return trans_choice(':count day before expiry|:count days before expiry', (int) ($settings['days'] ?? 0));
    }

    public function due(array $settings, CarbonImmutable $now): Builder
    {
        $day = $now->addDays((int) ($settings['days'] ?? 0));

        return Account::query()
            ->whereBetween('expiry', [$day->startOfDay(), $day->endOfDay()])
            ->where('expiry', '>', $now);
    }

    public function onceKey(int $ruleId, Account $account, ?Recharge $recharge): string
    {
        return "rule:{$ruleId}:account:{$account->id}:expiry:".$account->expiry?->format('Y-m-d H:i:s');
    }
}
