<?php

namespace App\Notifications\Contracts;

use App\Models\Account;
use App\Models\Recharge;
use App\Notifications\SettingField;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A kind of event a rule can send on. Scheduled triggers say which customers are due now; event triggers are
 * fired by the code where the event happens. See CONTRIBUTING.md, "Add a trigger".
 */
interface Trigger
{
    public static function key(): string;

    public static function label(): string;

    /** @return list<SettingField> */
    public static function settingFields(): array;

    /** @return list<string> placeholder keys from App\Notifications\Placeholders this trigger can fill */
    public static function placeholders(): array;

    public static function scheduled(): bool;

    /**
     * One line for the rules list, e.g. "3 days before expiry".
     *
     * @param  array<string, mixed>  $settings
     */
    public static function summary(array $settings): string;

    /**
     * The customers due now. Only a query: the core adds the rule's filters and the "No reminders" switch.
     * Event triggers return an empty query.
     *
     * @param  array<string, mixed>  $settings
     * @return Builder<Account>
     */
    public function due(array $settings, CarbonImmutable $now): Builder;

    /** Unique per message that must go out only once, e.g. "rule:12:account:34:expiry:2026-11-01 00:00:00". */
    public function onceKey(int $ruleId, Account $account, ?Recharge $recharge): string;
}
