<?php

namespace App\Notifications\Triggers;

use App\Models\Account;
use App\Models\Recharge;
use App\Notifications\Contracts\PaymentTrigger;
use App\Notifications\Contracts\Trigger;
use App\Notifications\PaymentNotice;
use App\Notifications\Placeholders;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fired by SettleOnlinePayment once a quick pay payment is on the books: the customer's confirmation.
 */
final class OnlinePaymentReceived implements PaymentTrigger, Trigger
{
    public static function key(): string
    {
        return 'online_payment_received';
    }

    public static function label(): string
    {
        return __('Online payment received');
    }

    public static function settingFields(): array
    {
        return [];
    }

    public static function placeholders(): array
    {
        return [...Placeholders::COMMON, ...Placeholders::PAYMENT];
    }

    public static function scheduled(): bool
    {
        return false;
    }

    public static function summary(array $settings): string
    {
        return __('When an online payment is received');
    }

    public function due(array $settings, CarbonImmutable $now): Builder
    {
        return Account::query()->whereRaw('1 = 0');
    }

    public function onceKey(int $ruleId, Account $account, ?Recharge $recharge): string
    {
        return "rule:{$ruleId}:account:{$account->id}:online-payment";
    }

    public function paymentOnceKey(int $ruleId, Account $account, PaymentNotice $payment): string
    {
        return "rule:{$ruleId}:online-payment:{$payment->key}";
    }
}
