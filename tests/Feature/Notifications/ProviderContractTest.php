<?php

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Notifications\Contracts\Provider;
use App\Notifications\OutgoingMessage;
use App\Notifications\Providers\Msg91Sms;
use App\Notifications\Providers\WacrmWhatsApp;
use Illuminate\Support\Facades\Http;

/*
| Every provider, built in or contributed, is added to this dataset and must pass every test below.
| 'url' is the pattern Http::fake matches the send request on; the three responses are what the API
| answers for a sent message, a refusal (that echoes the key back) and a rate limit of 30 seconds.
*/
dataset('providers', [
    'wacrm' => [fn (): array => [
        'class' => WacrmWhatsApp::class,
        'settings' => ['base_url' => 'https://crm.example.test', 'api_key' => 'wacrm_live_contract_secret'],
        'template' => ['name' => 'recharge_success', 'language' => 'en'],
        'url' => 'crm.example.test/api/v1/messages',
        'sent' => Http::response(['data' => ['message_id' => 'm-1', 'whatsapp_message_id' => 'wamid.1']], 201),
        'messageId' => 'wamid.1',
        'refused' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'Bad key wacrm_live_contract_secret']], 401),
        'rateLimited' => Http::response(['error' => ['code' => 'rate_limited', 'message' => 'Slow down']], 429, ['Retry-After' => '30']),
    ]],
    'msg91' => [fn (): array => [
        'class' => Msg91Sms::class,
        'settings' => ['auth_key' => 'msg91-contract-secret'],
        'template' => ['template_id' => '64f0c0ffee', 'variables' => ''],
        'url' => 'control.msg91.com/api/v5/flow',
        'sent' => Http::response(['type' => 'success', 'message' => '3763646c3058373530393831']),
        'messageId' => '3763646c3058373530393831',
        'refused' => Http::response(['type' => 'error', 'message' => 'Authentication failure msg91-contract-secret'], 401),
        'rateLimited' => Http::response(['type' => 'error', 'message' => 'Too many requests'], 429, ['Retry-After' => '30']),
    ]],
]);

function contractMessage(array $case): OutgoingMessage
{
    return new OutgoingMessage('+919876543210', $case['template'], ['2000.00', '25M'], null, '1');
}

function contractLog(array $case): NotificationLog
{
    NotificationProvider::query()->updateOrCreate(['provider' => $case['class']::key()], ['enabled' => true, 'settings' => $case['settings']]);

    return NotificationLog::factory()->create([
        'provider' => $case['class']::key(), 'channel' => $case['class']::channel(),
        'to' => '+919876543210', 'template' => $case['template'], 'values' => ['2000.00', '25M'],
    ]);
}

function runContractJob(NotificationLog $log): SendNotification
{
    $job = (new SendNotification($log->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('describes itself: a key, a channel, template fields and its secrets marked', function (Closure $case) {
    /** @var class-string<Provider> $class */
    $class = $case()['class'];

    expect($class::key())->toMatch('/^[a-z0-9_]+$/')
        ->and($class::templateFields())->not->toBeEmpty()
        ->and(collect($class::settingFields())->filter->isSecret())->not->toBeEmpty();
})->with('providers');

it('sends and returns the provider message id', function (Closure $case) {
    $case = $case();
    Http::fake([$case['url'] => $case['sent']]);

    $result = (new $case['class']($case['settings']))->send(contractMessage($case));

    expect($result->sent)->toBeTrue()->and($result->providerMessageId)->toBe($case['messageId']);
})->with('providers');

it('reports a refusal as final, with a reason', function (Closure $case) {
    $case = $case();
    Http::fake([$case['url'] => $case['refused']]);

    $result = (new $case['class']($case['settings']))->send(contractMessage($case));

    expect($result->sent)->toBeFalse()->and($result->retryable)->toBeFalse()->and($result->reason)->not->toBe('');
})->with('providers');

it('asks to be retried after the wait the API gives', function (Closure $case) {
    $case = $case();
    Http::fake([$case['url'] => $case['rateLimited']]);

    $result = (new $case['class']($case['settings']))->send(contractMessage($case));

    expect($result->retryable)->toBeTrue()->and($result->retryAfterSeconds)->toBe(30);
})->with('providers');

it('treats a server error as retryable', function (Closure $case) {
    $case = $case();
    Http::fake([$case['url'] => Http::response('Bad gateway', 502)]);

    expect((new $case['class']($case['settings']))->send(contractMessage($case))->retryable)->toBeTrue();
})->with('providers');

it('is retried by the job when the API cannot be reached', function (Closure $case) {
    $case = $case();
    Http::fake([$case['url'] => Http::failedConnection('Connection timed out')]);
    $log = contractLog($case);

    runContractJob($log)->assertReleased();

    expect($log->fresh()->status)->toBe(NotificationStatus::Queued);
})->with('providers');

it('never lets the key reach the log', function (Closure $case) {
    $case = $case();
    Http::fake([$case['url'] => $case['refused']]);
    $log = contractLog($case);

    runContractJob($log);

    foreach ($case['class']::settingFields() as $field) {
        if ($field->isSecret()) {
            expect((string) $log->fresh()->error)->not->toContain($case['settings'][$field->name]);
        }
    }
    expect($log->fresh()->status)->toBe(NotificationStatus::Failed);
})->with('providers');
