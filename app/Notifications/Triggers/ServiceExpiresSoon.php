<?php

namespace App\Notifications\Triggers;

use App\Models\Account;
use App\Models\CustomerService;
use App\Models\Recharge;
use App\Notifications\Contracts\ServiceTrigger;
use App\Notifications\Placeholders;
use App\Notifications\SettingField;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer services (a static IP, OTT) paid until the calendar day N days from today (server timezone).
 */
final class ServiceExpiresSoon implements ServiceTrigger
{
    public static function key(): string
    {
        return 'service_expiring';
    }

    public static function label(): string
    {
        return __('Service expires soon');
    }

    public static function settingFields(): array
    {
        return [new SettingField('days', __('Days before expiry'), 'number', min: 1, max: 60)];
    }

    public static function placeholders(): array
    {
        return [...Placeholders::COMMON, ...Placeholders::SERVICE];
    }

    public static function scheduled(): bool
    {
        return true;
    }

    public static function summary(array $settings): string
    {
        return trans_choice(':count day before a service expires|:count days before a service expires', (int) ($settings['days'] ?? 0));
    }

    public function due(array $settings, CarbonImmutable $now): Builder
    {
        return Account::query()->whereRaw('0 = 1');
    }

    public function dueServices(array $settings, CarbonImmutable $now): Builder
    {
        $day = $now->addDays((int) ($settings['days'] ?? 0));

        return CustomerService::query()
            ->whereNull('ended_at')
            ->whereBetween('paid_until', [$day->startOfDay(), $day->endOfDay()])
            ->where('paid_until', '>', $now);
    }

    public function onceKey(int $ruleId, Account $account, ?Recharge $recharge): string
    {
        return "rule:{$ruleId}:account:{$account->id}:service";
    }

    public function serviceOnceKey(int $ruleId, CustomerService $service): string
    {
        return "rule:{$ruleId}:service:{$service->id}:paid:".$service->paid_until->format('Y-m-d H:i:s');
    }
}
