<?php

use App\Notifications\NotificationRegistry;
use App\Notifications\SettingField;
use Tests\Feature\Notifications\FakeProvider;

it('registers a provider by its key and makes it with its settings', function () {
    $registry = new NotificationRegistry;
    $registry->registerProvider(FakeProvider::class);

    $provider = $registry->makeProvider('fake', ['token' => 'abc12345', 'sender' => 'ISPGRD']);

    expect($registry->providers())->toBe(['fake' => FakeProvider::class])
        ->and($provider)->toBeInstanceOf(FakeProvider::class)
        ->and($provider->settings['sender'])->toBe('ISPGRD')
        ->and($registry->makeProvider('missing', []))->toBeNull();
});

it('lists only the secret setting values, empty ones left out', function () {
    $registry = new NotificationRegistry;
    $registry->registerProvider(FakeProvider::class);

    expect($registry->secretValues('fake', ['token' => 'abc12345', 'sender' => 'ISPGRD']))->toBe(['abc12345'])
        ->and($registry->secretValues('fake', ['token' => '', 'sender' => 'ISPGRD']))->toBe([]);
});

it('builds validation rules from a setting field', function () {
    expect((new SettingField('days', 'Days', 'number', min: 1, max: 60))->rules())->toBe(['required', 'integer', 'between:1,60'])
        ->and((new SettingField('base_url', 'URL', 'url', required: false))->rules())->toBe(['nullable', 'string', 'max:255', 'url:https,http'])
        ->and((new SettingField('name', 'Name', maxLength: 100))->rules())->toBe(['required', 'string', 'max:100']);
});

it('is one shared registry for the app', function () {
    expect(app(NotificationRegistry::class))->toBe(app(NotificationRegistry::class));
});
