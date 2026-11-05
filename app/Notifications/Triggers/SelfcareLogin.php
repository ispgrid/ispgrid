<?php

namespace App\Notifications\Triggers;

use App\Models\Account;
use App\Models\Recharge;
use App\Notifications\Contracts\Trigger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fired with a new selfcare password: by Send on the customer page, and by the customer form when staff add a
 * customer. Never scheduled and never by the CSV import. Every send has its own password, so its once-key changes
 * with the password and a second Send is never taken for a duplicate.
 */
final class SelfcareLogin implements Trigger
{
    public static function key(): string
    {
        return 'selfcare_login';
    }

    public static function label(): string
    {
        return __('Selfcare login');
    }

    public static function settingFields(): array
    {
        return [];
    }

    public static function placeholders(): array
    {
        return ['customer_name', 'username', 'selfcare_url', 'selfcare_password', 'isp_name', 'support_number'];
    }

    public static function scheduled(): bool
    {
        return false;
    }

    public static function summary(array $settings): string
    {
        return __('When staff send a selfcare password');
    }

    public function due(array $settings, CarbonImmutable $now): Builder
    {
        return Account::query()->whereRaw('1 = 0');
    }

    public function onceKey(int $ruleId, Account $account, ?Recharge $recharge): string
    {
        return "rule:{$ruleId}:selfcare:{$account->id}:".substr(hash('sha256', (string) $account->selfcare_password), 0, 16);
    }
}
