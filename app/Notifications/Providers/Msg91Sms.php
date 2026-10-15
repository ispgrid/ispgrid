<?php

namespace App\Notifications\Providers;

use App\Enums\NotificationChannel;
use App\Notifications\Contracts\Provider;
use App\Notifications\OutgoingMessage;
use App\Notifications\SendResult;
use App\Notifications\SettingField;
use Closure;
use Illuminate\Support\Facades\Http;

/**
 * SMS through MSG91's template ("flow") API (https://docs.msg91.com/sms/send-sms). In India every SMS uses a
 * DLT-registered template; MSG91 maps its template ID to the DLT one. Variables are matched by name.
 */
final class Msg91Sms implements Provider
{
    private const URL = 'https://control.msg91.com/api/v5/flow';

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(private array $settings) {}

    public static function key(): string
    {
        return 'msg91';
    }

    public static function label(): string
    {
        return 'MSG91 (SMS)';
    }

    public static function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    public static function settingFields(): array
    {
        return [new SettingField('auth_key', __('Auth key'), 'secret', help: __('In MSG91 under Authkey.'), maxLength: 100)];
    }

    public static function templateFields(): array
    {
        return [
            new SettingField('template_id', __('MSG91 template ID'), help: __('The template linked to your DLT template.'), maxLength: 50, rules: [self::notDltTemplateId(...)]),
            new SettingField('variables', __('Variable names'), required: false, help: __('Comma separated, as in the template. Leave empty for VAR1, VAR2, ...'), maxLength: 200),
        ];
    }

    public static function supportsConnectionTest(): bool
    {
        return false;
    }

    public function send(OutgoingMessage $message): SendResult
    {
        $response = Http::withHeaders(['authkey' => $this->settings['auth_key'] ?? ''])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(self::URL, [
                'template_id' => $message->template['template_id'] ?? '',
                'short_url' => '0',
                'recipients' => [['mobiles' => ltrim($message->to, '+'), ...$this->variables($message)]],
            ]);

        if ($response->successful() && $response->json('type') === 'success') {
            return SendResult::sent((string) $response->json('message', ''));
        }

        $retryAfter = $response->header('Retry-After');

        return SendResult::failed(
            (string) ($response->json('message') ?? 'http_'.$response->status().': '.$response->reason()),
            $response->serverError() || $response->status() === 429,
            $retryAfter !== '' ? (int) $retryAfter : null,
        );
    }

    public function testConnection(): SendResult
    {
        return SendResult::failed(__('MSG91 has no check that sends nothing. Use Send test on a rule.'));
    }

    /**
     * MSG91 answers "success" even for a template ID it does not know, and sends nothing. A DLT template ID (all
     * digits) is the usual mistake, so it is refused here.
     */
    private static function notDltTemplateId(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/^[0-9]{12,}$/', $value) === 1) {
            $fail(__('This is a DLT template ID. Enter the MSG91 template ID from the Templates page in MSG91 (24 letters and digits).'));
        }
    }

    /**
     * @return array<string, string>
     */
    private function variables(OutgoingMessage $message): array
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $message->template['variables'] ?? ''))));
        $variables = [];

        foreach ($message->values as $index => $value) {
            $variables[$names[$index] ?? 'VAR'.($index + 1)] = $value;
        }

        return $variables;
    }
}
