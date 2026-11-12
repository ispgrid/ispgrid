<?php

namespace App\Notifications\Triggers;

use App\Models\Account;
use App\Models\Recharge;
use App\Notifications\Contracts\Trigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fired with a new PPPoE password by Reset and send on the customer page. Never scheduled. Every send has its own
 * password, so its once-key changes with the password and a second reset is never taken for a duplicate.
 */
final class PppoePassword implements Trigger
{
    public static function key(): string
    {
        return 'pppoe_password';
    }

    public static function label(): string
    {
        return __('PPPoE password');
    }

    public static function settingFields(): array
    {
        return [];
    }

    public static function placeholders(): array
    {
        return ['customer_name', 'username', 'pppoe_password', 'isp_name', 'support_number'];
    }

    public static function scheduled(): bool
    {
        return false;
    }

    public static function summary(array $settings): string
    {
        return __('When staff reset a PPPoE password and send it');
    }

    public function due(array $settings, CarbonImmutable $now): Builder
    {
        return Account::query()->whereRaw('1 = 0');
    }

    public function onceKey(int $ruleId, Account $account, ?Recharge $recharge): string
    {
        return "rule:{$ruleId}:pppoe:{$account->id}:".substr(hash_hmac('sha256', (string) $account->password, (string) config('app.key')), 0, 16);
    }
}
