<?php

namespace App\Notifications\Triggers;

use App\Models\Account;
use App\Models\Recharge;
use App\Notifications\Contracts\Trigger;
use App\Notifications\Placeholders;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fired by RechargeAccount once the recharge is saved.
 */
final class RechargeDone implements Trigger
{
    public static function key(): string
    {
        return 'recharge_done';
    }

    public static function label(): string
    {
        return __('Recharge done');
    }

    public static function settingFields(): array
    {
        return [];
    }

    public static function placeholders(): array
    {
        return [...Placeholders::COMMON, ...Placeholders::RECHARGE];
    }

    public static function scheduled(): bool
    {
        return false;
    }

    public static function summary(array $settings): string
    {
        return __('When a recharge is done');
    }

    public function due(array $settings, CarbonImmutable $now): Builder
    {
        return Account::query()->whereRaw('1 = 0');
    }

    public function onceKey(int $ruleId, Account $account, ?Recharge $recharge): string
    {
        return "rule:{$ruleId}:recharge:".$recharge?->id;
    }
}
