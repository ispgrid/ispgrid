<?php

namespace App\Notifications\Triggers;

use App\Models\Account;
use App\Models\CustomerService;
use App\Models\Recharge;
use App\Notifications\Contracts\ServiceTrigger;
use App\Notifications\Placeholders;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer services whose paid-until date passed in the last 2 days: sent once, so one that expires after the
 * sending hours still goes out the next morning.
 */
final class ServiceExpired implements ServiceTrigger
{
    private const WINDOW_DAYS = 2;

    public static function key(): string
    {
        return 'service_expired';
    }

    public static function label(): string
    {
        return __('Service expired');
    }

    public static function settingFields(): array
    {
        return [];
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
        return __('When a service expires');
    }

    public function due(array $settings, CarbonImmutable $now): Builder
    {
        return Account::query()->whereRaw('0 = 1');
    }

    public function dueServices(array $settings, CarbonImmutable $now): Builder
    {
        return CustomerService::query()
            ->whereNull('ended_at')
            ->where('paid_until', '>', $now->subDays(self::WINDOW_DAYS))
            ->where('paid_until', '<=', $now);
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
