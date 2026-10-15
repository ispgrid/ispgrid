<?php

use App\Models\Account;
use App\Models\Recharge;
use App\Notifications\Contracts\Trigger;
use App\Notifications\NotificationRegistry;
use App\Notifications\Triggers\AfterExpiry;
use App\Notifications\Triggers\BeforeExpiry;
use App\Notifications\Triggers\CustomerCreated;
use App\Notifications\Triggers\RechargeDone;
use Carbon\CarbonImmutable;

function dueUsernames(Trigger $trigger, array $settings, string $now): array
{
    return $trigger->due($settings, CarbonImmutable::parse($now))->orderBy('username')->pluck('username')->all();
}

it('finds customers whose plan ends on the day N days from today', function () {
    Account::factory()->create(['username' => 'early', 'expiry' => '2026-08-22 00:00:00']);
    Account::factory()->create(['username' => 'late', 'expiry' => '2026-08-22 23:59:00']);
    Account::factory()->create(['username' => 'day_after', 'expiry' => '2026-08-23 00:00:00']);
    Account::factory()->create(['username' => 'never', 'expiry' => null]);
    Account::factory()->trashed()->create(['username' => 'gone', 'expiry' => '2026-08-22 12:00:00']);

    expect(dueUsernames(new BeforeExpiry, ['days' => 3], '2026-08-19 23:50'))->toBe(['early', 'late']);
});

it('finds customers whose plan ended N days ago, and today for zero', function () {
    Account::factory()->create(['username' => 'this_morning', 'expiry' => '2026-08-19 06:00:00']);
    Account::factory()->create(['username' => 'tonight', 'expiry' => '2026-08-19 23:00:00']);
    Account::factory()->create(['username' => 'three_ago', 'expiry' => '2026-08-16 18:00:00']);

    expect(dueUsernames(new AfterExpiry, ['days' => 0], '2026-08-19 10:00'))->toBe(['this_morning'])
        ->and(dueUsernames(new AfterExpiry, ['days' => 3], '2026-08-19 10:00'))->toBe(['three_ago']);
});

it('keys expiry reminders by the expiry, so a recharge starts them again', function () {
    $account = Account::factory()->create(['expiry' => '2026-08-22 23:33:00']);
    $before = (new BeforeExpiry)->onceKey(7, $account, null);
    $account->update(['expiry' => '2026-09-21 23:33:00']);

    expect($before)->toBe("rule:7:account:{$account->id}:expiry:2026-08-22 23:33:00")
        ->and((new BeforeExpiry)->onceKey(7, $account, null))->not->toBe($before);
});

it('keys event triggers by the recharge and the customer', function () {
    $account = Account::factory()->create();
    $recharge = Recharge::query()->create(['account_id' => $account->id, 'plan_id' => $account->plan_id, 'new_expiry' => now(), 'amount' => '10']);

    expect((new RechargeDone)->onceKey(2, $account, $recharge))->toBe("rule:2:recharge:{$recharge->id}")
        ->and((new CustomerCreated)->onceKey(2, $account, null))->toBe("rule:2:created:{$account->id}")
        ->and((new RechargeDone)->due([], CarbonImmutable::now())->count())->toBe(0);
});

it('offers recharge values only to the recharge trigger and never a password', function () {
    expect(RechargeDone::placeholders())->toContain('amount_paid', 'recharge_reference')
        ->and(BeforeExpiry::placeholders())->not->toContain('amount_paid')
        ->and(CustomerCreated::placeholders())->not->toContain('expiry_date')
        ->and(array_merge(BeforeExpiry::placeholders(), RechargeDone::placeholders(), CustomerCreated::placeholders()))->not->toContain('password');
});

it('describes a rule in one line', function () {
    expect(BeforeExpiry::summary(['days' => 3]))->toBe('3 days before expiry')
        ->and(BeforeExpiry::summary(['days' => 1]))->toBe('1 day before expiry')
        ->and(AfterExpiry::summary(['days' => 0]))->toBe('On the day it expires')
        ->and(AfterExpiry::summary(['days' => 2]))->toBe('2 days after expiry');
});

it('registers the four built-in triggers', function () {
    expect(array_keys(app(NotificationRegistry::class)->triggers()))
        ->toBe(['before_expiry', 'after_expiry', 'recharge_done', 'customer_created']);
});

it('catches up next morning on a same-day message for a plan that ended after the sending hours', function () {
    Account::factory()->create(['username' => 'late_last_night', 'expiry' => '2026-08-18 22:00:00']);
    Account::factory()->create(['username' => 'yesterday_noon', 'expiry' => '2026-08-18 12:00:00']);

    expect(dueUsernames(new AfterExpiry, ['days' => 0], '2026-08-19 09:00'))->toBe(['late_last_night']);
});
