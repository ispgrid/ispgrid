<?php

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Models\Plan;
use App\Models\Recharge;
use App\Notifications\NotificationRegistry;
use App\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Notifications\FakeProvider;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    app(NotificationRegistry::class)->registerProvider(FakeProvider::class);
    NotificationProvider::query()->create(['provider' => 'fake', 'enabled' => true, 'settings' => ['token' => 'tok12345']]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-19 10:00'));
});

function notifier(): Notifier
{
    return app(Notifier::class);
}

it('queues one message per due customer and never twice', function () {
    $rule = NotificationRule::factory()->enabled()->create(['trigger_settings' => ['days' => 3]]);
    $account = Account::factory()->create(['fullname' => 'Asha', 'mobile' => '9876543210', 'expiry' => '2026-08-22 23:33:00']);
    Account::factory()->create(['expiry' => '2026-08-25 10:00:00']);

    expect(notifier()->dispatchScheduled(CarbonImmutable::now()))->toBe(1)
        ->and(notifier()->dispatchScheduled(CarbonImmutable::now()->addMinutes(15)))->toBe(0);

    $log = NotificationLog::query()->sole();
    expect($log->account_id)->toBe($account->id)
        ->and($log->notification_rule_id)->toBe($rule->id)
        ->and($log->to)->toBe('+919876543210')
        ->and($log->values)->toBe(['Asha', '22-Aug-2026'])
        ->and($log->template)->toBe(['template_id' => 'tmpl-1'])
        ->and($log->preview)->toBe('Dear Asha, your plan expires on 22-Aug-2026.')
        ->and($log->status)->toBe(NotificationStatus::Queued);
    Queue::assertPushed(SendNotification::class, 1);
});

it('sends a fresh reminder after a recharge moves the expiry', function () {
    NotificationRule::factory()->enabled()->create(['trigger_settings' => ['days' => 3]]);
    $account = Account::factory()->create(['expiry' => '2026-08-22 23:33:00']);
    notifier()->dispatchScheduled(CarbonImmutable::now());

    $account->update(['expiry' => '2026-09-21 23:33:00']);
    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00'));

    expect(notifier()->dispatchScheduled(CarbonImmutable::now()))->toBe(1)
        ->and(NotificationLog::query()->count())->toBe(2);
});

it('sends nothing outside the sending hours, and catches up inside them', function () {
    NotificationRule::factory()->enabled()->create(['trigger_settings' => ['days' => 3]]);
    Account::factory()->create(['expiry' => '2026-08-22 12:00:00']);

    expect(notifier()->dispatchScheduled(CarbonImmutable::parse('2026-08-19 07:00')))->toBe(0)
        ->and(notifier()->dispatchScheduled(CarbonImmutable::parse('2026-08-19 21:00')))->toBe(0)
        ->and(notifier()->dispatchScheduled(CarbonImmutable::parse('2026-08-19 09:00')))->toBe(1);
});

it('honours the rule filters, the off switch and a switched-off provider', function () {
    $home = Plan::factory()->create();
    NotificationRule::factory()->enabled()->create(['trigger_settings' => ['days' => 3], 'filters' => ['plans' => [$home->id], 'service_types' => ['PPP'], 'statuses' => [1]]]);
    Account::factory()->create(['username' => 'match', 'plan_id' => $home->id, 'expiry' => '2026-08-22 12:00:00']);
    Account::factory()->create(['username' => 'other_plan', 'expiry' => '2026-08-22 12:00:00']);
    Account::factory()->suspended()->create(['username' => 'suspended', 'plan_id' => $home->id, 'expiry' => '2026-08-22 12:00:00']);
    Account::factory()->create(['username' => 'opted_out', 'plan_id' => $home->id, 'expiry' => '2026-08-22 12:00:00', 'notifications_off' => true]);
    NotificationRule::factory()->create(['trigger_settings' => ['days' => 3]]); // off

    notifier()->dispatchScheduled(CarbonImmutable::now());
    expect(NotificationLog::query()->with('account')->get()->pluck('account.username')->all())->toBe(['match']);

    NotificationProvider::query()->update(['enabled' => false]);
    Account::factory()->create(['plan_id' => $home->id, 'expiry' => '2026-08-22 13:00:00']);
    expect(notifier()->dispatchScheduled(CarbonImmutable::now()))->toBe(0);
});

it('skips a customer without a usable number and says why', function () {
    NotificationRule::factory()->enabled()->create(['trigger_settings' => ['days' => 3]]);
    Account::factory()->create(['mobile' => null, 'expiry' => '2026-08-22 12:00:00']);
    Account::factory()->create(['mobile' => '12345', 'expiry' => '2026-08-22 13:00:00']);

    notifier()->dispatchScheduled(CarbonImmutable::now());

    expect(NotificationLog::query()->orderBy('id')->get()->map(fn (NotificationLog $log): array => [$log->status, $log->error])->all())->toBe([
        [NotificationStatus::Skipped, 'No mobile number.'],
        [NotificationStatus::Skipped, 'Invalid number: 12345'],
    ]);
    Queue::assertNothingPushed();
});

it('fires event rules for the customer at once, at any hour, and only once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-19 23:30'));
    NotificationRule::factory()->enabled()->create(['trigger' => 'recharge_done', 'trigger_settings' => [], 'values' => ['amount_paid', 'recharge_reference']]);
    $account = Account::factory()->create(['notifications_off' => true]);
    $recharge = Recharge::query()->create(['account_id' => $account->id, 'plan_id' => $account->plan_id, 'new_expiry' => now()->addMonth(), 'amount' => '2000']);

    notifier()->fire('recharge_done', $account, $recharge);
    notifier()->fire('recharge_done', $account, $recharge);

    expect(NotificationLog::query()->sole()->values)->toBe(['2000.00', (string) $recharge->id]);
    Queue::assertPushed(SendNotification::class, 1);
});

it('retries a failed message as the same row', function () {
    $log = NotificationLog::factory()->failed()->create();

    notifier()->retry($log);

    expect($log->fresh()->status)->toBe(NotificationStatus::Queued)
        ->and($log->fresh()->attempts)->toBe(0)
        ->and($log->fresh()->error)->toBeNull();
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->logId === $log->id);
});

it('reads the sending hours from the settings', function () {
    AppSetting::put('notify_hours_from', '08:30');
    AppSetting::put('notify_hours_to', '20:00');

    expect(Notifier::withinHours(CarbonImmutable::parse('2026-08-19 08:29')))->toBeFalse()
        ->and(Notifier::withinHours(CarbonImmutable::parse('2026-08-19 08:30')))->toBeTrue()
        ->and(Notifier::withinHours(CarbonImmutable::parse('2026-08-19 19:59')))->toBeTrue()
        ->and(Notifier::withinHours(CarbonImmutable::parse('2026-08-19 20:00')))->toBeFalse();
});

it('runs from the scheduler every 15 minutes', function () {
    NotificationRule::factory()->enabled()->create(['trigger_settings' => ['days' => 3]]);
    Account::factory()->create(['expiry' => '2026-08-22 12:00:00']);

    $this->artisan('notifications:dispatch')->expectsOutput('Queued 1 message.')->assertSuccessful();
});

it('retries with the template the rule has now', function () {
    $rule = NotificationRule::factory()->create(['template' => ['template_id' => 'fixed']]);
    $log = NotificationLog::factory()->failed()->create(['notification_rule_id' => $rule->id, 'template' => ['template_id' => 'typo']]);

    notifier()->retry($log);

    expect($log->fresh()->template)->toBe(['template_id' => 'fixed']);
});
