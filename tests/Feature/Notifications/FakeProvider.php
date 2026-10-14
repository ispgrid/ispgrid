<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannel;
use App\Notifications\Contracts\Provider;
use App\Notifications\OutgoingMessage;
use App\Notifications\SendResult;
use App\Notifications\SettingField;

/**
 * A provider for core tests: answers with whatever the test put in $next and remembers what it was asked to send.
 */
final class FakeProvider implements Provider
{
    /** @var list<OutgoingMessage> */
    public static array $sent = [];

    public static ?SendResult $next = null;

    public static ?\Throwable $throw = null;

    /**
     * @param  array<string, string>  $settings
     */
    public function __construct(public array $settings) {}

    public static function key(): string
    {
        return 'fake';
    }

    public static function label(): string
    {
        return 'Fake (SMS)';
    }

    public static function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    public static function settingFields(): array
    {
        return [new SettingField('token', 'Token', 'secret'), new SettingField('sender', 'Sender', maxLength: 6)];
    }

    public static function templateFields(): array
    {
        return [new SettingField('template_id', 'Template ID', maxLength: 50)];
    }

    public static function supportsConnectionTest(): bool
    {
        return true;
    }

    public function send(OutgoingMessage $message): SendResult
    {
        if (self::$throw !== null) {
            throw self::$throw;
        }

        self::$sent[] = $message;

        return self::$next ?? SendResult::sent('fake-'.count(self::$sent));
    }

    public function testConnection(): SendResult
    {
        return self::$next ?? SendResult::sent('');
    }

    public static function reset(): void
    {
        self::$sent = [];
        self::$next = null;
        self::$throw = null;
    }
}
