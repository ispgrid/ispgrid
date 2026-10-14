<?php

namespace App\Notifications\Contracts;

use App\Enums\NotificationChannel;
use App\Notifications\OutgoingMessage;
use App\Notifications\SendResult;
use App\Notifications\SettingField;

/**
 * Delivers one message on one channel. A provider decides nothing about who gets a message or when: the core
 * does that, writes the log and handles retries. See CONTRIBUTING.md, "Add a notification provider".
 */
interface Provider
{
    /**
     * @param  array<string, string>  $settings  the decrypted values of settingFields()
     */
    public function __construct(array $settings);

    /** Stored in rules and the log: lowercase, never changed once released. */
    public static function key(): string;

    public static function label(): string;

    public static function channel(): NotificationChannel;

    /** @return list<SettingField> connection settings; mark API keys 'secret' */
    public static function settingFields(): array;

    /** @return list<SettingField> what a rule fills in to pick a template */
    public static function templateFields(): array;

    public static function supportsConnectionTest(): bool;

    public function send(OutgoingMessage $message): SendResult;

    /** Checks the settings without messaging anyone. Only called when supportsConnectionTest() is true. */
    public function testConnection(): SendResult;
}
