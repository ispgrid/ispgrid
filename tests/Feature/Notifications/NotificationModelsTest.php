<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Account;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

it('stores provider settings encrypted', function () {
    NotificationProvider::query()->create(['provider' => 'wacrm', 'enabled' => true, 'settings' => ['api_key' => 'wacrm_live_secret']]);

    expect(DB::table('notification_providers')->value('settings'))->not->toContain('wacrm_live_secret')
        ->and(NotificationProvider::settingsFor('wacrm'))->toBe(['api_key' => 'wacrm_live_secret'])
        ->and(NotificationProvider::settingsFor('msg91'))->toBe([])
        ->and(NotificationProvider::enabledKeys())->toBe(['wacrm']);
});

it('refuses a second log row with the same once-key', function () {
    NotificationLog::factory()->create(['once_key' => 'rule:1:account:1:expiry:x']);

    expect(fn () => NotificationLog::factory()->create(['once_key' => 'rule:1:account:1:expiry:x']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('builds the provider message from a log row', function () {
    $log = NotificationLog::factory()->create([
        'to' => '+919876543210', 'template' => ['name' => 'recharge_success', 'language' => 'en'],
        'values' => ['2000.00', '25M'], 'preview' => 'Paid 2000.00',
    ]);

    $message = $log->toMessage();

    expect($message->to)->toBe('+919876543210')
        ->and($message->template)->toBe(['name' => 'recharge_success', 'language' => 'en'])
        ->and($message->values)->toBe(['2000.00', '25M'])
        ->and($message->reference)->toBe((string) $log->id);
});

it('keeps a rule with its casts and logs its changes', function () {
    $rule = NotificationRule::factory()->create(['trigger_settings' => ['days' => 3], 'values' => ['customer_name', 'expiry_date']]);

    expect($rule->fresh()->trigger_settings)->toBe(['days' => 3])
        ->and($rule->fresh()->values)->toBe(['customer_name', 'expiry_date'])
        ->and($rule->fresh()->enabled)->toBeFalse()
        ->and(Activity::query()->where('subject_type', $rule->getMorphClass())->where('subject_id', $rule->id)->count())->toBe(1);
});

it('gives customers reminders unless switched off', function () {
    expect(Account::factory()->create()->fresh()->notifications_off)->toBeFalse();
});

it('casts the log channel and status', function () {
    $log = NotificationLog::factory()->create();

    expect($log->fresh()->channel)->toBe(NotificationChannel::Sms)
        ->and($log->fresh()->status)->toBe(NotificationStatus::Queued);
});
