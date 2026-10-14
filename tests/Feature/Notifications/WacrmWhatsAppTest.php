<?php

use App\Enums\NotificationChannel;
use App\Notifications\OutgoingMessage;
use App\Notifications\Providers\WacrmWhatsApp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function wacrm(): WacrmWhatsApp
{
    return new WacrmWhatsApp(['base_url' => 'https://crm.example.test/', 'api_key' => 'wacrm_live_k']);
}

it('sends a WhatsApp template with its values in order', function () {
    Http::fake(['crm.example.test/api/v1/messages' => Http::response(['data' => ['message_id' => 'm-1', 'whatsapp_message_id' => null]], 201)]);

    $result = wacrm()->send(new OutgoingMessage('+919876543210', ['name' => 'recharge_success', 'language' => 'en'], ['2000.00', '1GBPS', '22-Aug-2026 11:33PM'], null, '9'));

    expect($result->providerMessageId)->toBe('m-1')
        ->and(WacrmWhatsApp::channel())->toBe(NotificationChannel::WhatsApp);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://crm.example.test/api/v1/messages'
        && $request->hasHeader('Authorization', 'Bearer wacrm_live_k')
        && $request->data() === [
            'to' => '+919876543210',
            'type' => 'template',
            'template' => ['name' => 'recharge_success', 'language' => 'en', 'params' => ['2000.00', '1GBPS', '22-Aug-2026 11:33PM']],
        ]);
});

it('checks the key with the me endpoint', function () {
    Http::fake([
        'crm.example.test/api/v1/me' => Http::sequence()
            ->push(['data' => ['account' => 'x', 'scopes' => ['messages:send']]])
            ->push(['error' => ['code' => 'unauthorized', 'message' => 'Invalid API key']], 401),
    ]);

    expect(wacrm()->testConnection()->sent)->toBeTrue()
        ->and(wacrm()->testConnection()->reason)->toBe('unauthorized: Invalid API key');
});

it('treats a Meta rejection as retryable and a missing setup as final', function () {
    Http::fake(['crm.example.test/api/v1/messages' => Http::sequence()
        ->push(['error' => ['code' => 'meta_error', 'message' => 'Meta rejected']], 502)
        ->push(['error' => ['code' => 'whatsapp_not_configured', 'message' => 'Connect WhatsApp']], 400)]);
    $message = new OutgoingMessage('+919876543210', ['name' => 't', 'language' => 'en'], [], null, '1');

    expect(wacrm()->send($message)->retryable)->toBeTrue()
        ->and(wacrm()->send($message)->retryable)->toBeFalse();
});
