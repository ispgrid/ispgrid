<?php

namespace App\Notifications\Triggers;

use App\Models\Account;
use App\Models\Recharge;
use App\Notifications\Contracts\Trigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fired by the customer form when staff add a customer. Never by the CSV import, and never offers the password.
 */
final class CustomerCreated implements Trigger
{
    public static function key(): string
    {
        return 'customer_created';
    }

    public static function label(): string
    {
        return __('Customer created');
    }

    public static function settingFields(): array
    {
        return [];
    }

    public static function placeholders(): array
    {
        return ['customer_name', 'username', 'mobile', 'plan_name', 'plan_speed', 'isp_name', 'support_number'];
    }

    public static function scheduled(): bool
    {
        return false;
    }

    public static function summary(array $settings): string
    {
        return __('When a customer is added');
    }

    public function due(array $settings, CarbonImmutable $now): Builder
    {
        return Account::query()->whereRaw('1 = 0');
    }

    public function onceKey(int $ruleId, Account $account, ?Recharge $recharge): string
    {
        return "rule:{$ruleId}:created:{$account->id}";
    }
}
