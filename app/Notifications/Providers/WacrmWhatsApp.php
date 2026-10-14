<?php

namespace App\Notifications\Providers;

use App\Enums\NotificationChannel;
use App\Notifications\Contracts\Provider;
use App\Notifications\OutgoingMessage;
use App\Notifications\SendResult;
use App\Notifications\SettingField;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp through a self-hosted wacrm (https://wacrm.tech/docs/public-api). The API key needs the
 * messages:send scope. A template is picked by name and language and takes its values as an ordered list.
 */
final class WacrmWhatsApp implements Provider
{
    private const RETRYABLE = ['rate_limited', 'internal', 'meta_error'];

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'wacrm';
    }

    public static function label(): string
    {
        return 'wacrm (WhatsApp)';
    }

    public static function channel(): NotificationChannel
    {
        return NotificationChannel::WhatsApp;
    }

    public static function settingFields(): array
    {
        return [
            new SettingField('base_url', __('wacrm address'), 'url', help: __('Where your wacrm runs, e.g. https://crm.example.com')),
            new SettingField('api_key', __('API key'), 'secret', help: __('Settings, API keys in wacrm. Give it the messages:send scope.'), maxLength: 200),
        ];
    }

    public static function templateFields(): array
    {
        return [
            new SettingField('name', __('Template name'), maxLength: 100),
            new SettingField('language', __('Template language'), help: __('As approved, e.g. en or en_US'), maxLength: 20),
        ];
    }

    public static function supportsConnectionTest(): bool
    {
        return true;
    }

    public function send(OutgoingMessage $message): SendResult
    {
        $response = $this->client()->post('api/v1/messages', [
            'to' => $message->to,
            'type' => 'template',
            'template' => [
                'name' => $message->template['name'] ?? '',
                'language' => $message->template['language'] ?? '',
                'params' => $message->values,
            ],
        ]);

        if ($response->successful()) {
            return SendResult::sent((string) ($response->json('data.whatsapp_message_id') ?? $response->json('data.message_id') ?? ''));
        }

        return $this->failure($response);
    }

    public function testConnection(): SendResult
    {
        $response = $this->client()->get('api/v1/me');

        return $response->successful() ? SendResult::sent('') : $this->failure($response);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->settings['base_url'] ?? '', '/').'/')
            ->withToken($this->settings['api_key'] ?? '')
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }

    private function failure(Response $response): SendResult
    {
        $code = (string) ($response->json('error.code') ?? 'http_'.$response->status());
        $message = (string) ($response->json('error.message') ?? $response->reason());
        $retryAfter = $response->header('Retry-After');

        return SendResult::failed(
            "{$code}: {$message}",
            in_array($code, self::RETRYABLE, true) || $response->serverError(),
            $retryAfter !== '' ? (int) $retryAfter : null,
        );
    }
}
