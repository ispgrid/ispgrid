<?php

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Notifications\NotificationRegistry;
use App\Notifications\SendResult;
use Illuminate\Http\Client\ConnectionException;
use Tests\Feature\Notifications\FakeProvider;

beforeEach(function () {
    FakeProvider::reset();
    app(NotificationRegistry::class)->registerProvider(FakeProvider::class);
    NotificationProvider::query()->create(['provider' => 'fake', 'enabled' => true, 'settings' => ['token' => 'tok-secret-123']]);
});

function runJob(NotificationLog $log): SendNotification
{
    $job = (new SendNotification($log->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('marks the row sent with the provider id', function () {
    $log = NotificationLog::factory()->create(['to' => '+919876543210', 'values' => ['Asha']]);

    runJob($log)->assertNotReleased();

    expect($log->fresh()->status)->toBe(NotificationStatus::Sent)
        ->and($log->fresh()->provider_message_id)->toBe('fake-1')
        ->and($log->fresh()->sent_at)->not->toBeNull()
        ->and($log->fresh()->attempts)->toBe(1)
        ->and(FakeProvider::$sent[0]->to)->toBe('+919876543210')
        ->and(FakeProvider::$sent[0]->values)->toBe(['Asha']);
});

it('fails at once when the provider refuses for good', function () {
    FakeProvider::$next = SendResult::failed('bad_request: template not found');
    $log = NotificationLog::factory()->create();

    runJob($log)->assertNotReleased();

    expect($log->fresh()->status)->toBe(NotificationStatus::Failed)
        ->and($log->fresh()->error)->toBe('bad_request: template not found');
});

it('tries again after the wait the provider asks for', function () {
    FakeProvider::$next = SendResult::failed('rate_limited', retryable: true, retryAfterSeconds: 42);
    $log = NotificationLog::factory()->create();

    runJob($log)->assertReleased(delay: 42);

    expect($log->fresh()->status)->toBe(NotificationStatus::Queued)
        ->and($log->fresh()->error)->toBe('rate_limited');
});

it('treats a connection problem as retryable and keeps the key out of the error', function () {
    FakeProvider::$throw = new ConnectionException('cURL error 6 for https://api.example/send?token=tok-secret-123');
    $log = NotificationLog::factory()->create();

    runJob($log)->assertReleased(delay: 60);

    expect($log->fresh()->error)->toBe('cURL error 6 for https://api.example/send?token=••••')
        ->and($log->fresh()->error)->not->toContain('tok-secret-123');
});

it('fails a row whose provider was switched off meanwhile', function () {
    NotificationProvider::query()->update(['enabled' => false]);
    $log = NotificationLog::factory()->create();

    runJob($log);

    expect($log->fresh()->status)->toBe(NotificationStatus::Failed)
        ->and($log->fresh()->error)->toBe('The provider is switched off.')
        ->and(FakeProvider::$sent)->toBe([]);
});

it('leaves a row that is no longer queued alone', function () {
    $log = NotificationLog::factory()->sent()->create();

    runJob($log);

    expect(FakeProvider::$sent)->toBe([]);
});

it('gives up after the last try and keeps the reason', function () {
    FakeProvider::$next = SendResult::failed('internal', retryable: true);
    $log = NotificationLog::factory()->create();
    $job = (new SendNotification($log->id))->withFakeQueueInteractions();
    $job->job->attempts = 5;

    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($log->fresh()->status)->toBe(NotificationStatus::Failed)
        ->and($log->fresh()->error)->toBe('internal');
});
