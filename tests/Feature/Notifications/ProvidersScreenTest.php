<?php

use App\Enums\Permission;
use App\Models\AppSetting;
use App\Models\NotificationProvider;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->actingAs(User::factory()->create());
});

it('lists the built-in providers', function () {
    Livewire::test('pages::notifications.providers')->assertSee(['MSG91 (SMS)', 'wacrm (WhatsApp)']);
});

it('saves a provider with its key encrypted and keeps the key when the box is left empty', function () {
    Livewire::test('pages::notifications.providers')
        ->set('settings.wacrm.base_url', 'https://chat.example.test')
        ->set('settings.wacrm.api_key', 'wacrm_live_first')
        ->set('enabled.wacrm', true)
        ->call('save', 'wacrm')
        ->assertHasNoErrors()
        ->assertSet('settings.wacrm.api_key', '');

    Livewire::test('pages::notifications.providers')
        ->assertSee(__('Saved. Leave empty to keep it.'))
        ->set('settings.wacrm.base_url', 'https://chat2.example.test')
        ->call('save', 'wacrm')
        ->assertHasNoErrors();

    expect(NotificationProvider::settingsFor('wacrm'))->toBe(['base_url' => 'https://chat2.example.test', 'api_key' => 'wacrm_live_first'])
        ->and(NotificationProvider::enabledKeys())->toBe(['wacrm']);
});

it('asks for the required settings', function () {
    Livewire::test('pages::notifications.providers')
        ->set('settings.wacrm.base_url', 'not a url')
        ->call('save', 'wacrm')
        ->assertHasErrors(['settings.wacrm.base_url', 'settings.wacrm.api_key']);
});

it('logs the change without the key', function () {
    Livewire::test('pages::notifications.providers')
        ->set('settings.msg91.auth_key', 'msg91-very-secret')
        ->call('save', 'msg91');

    $activity = Activity::query()->latest('id')->first();

    expect(json_encode($activity->attribute_changes))->not->toContain('msg91-very-secret')
        ->and($activity->attribute_changes['attributes']['changed'])->toBe('Auth key');
});

it('tests the connection with the saved settings', function () {
    NotificationProvider::query()->create(['provider' => 'wacrm', 'enabled' => true, 'settings' => ['base_url' => 'https://chat.example.test', 'api_key' => 'k-123456']]);
    Http::fake(['chat.example.test/api/v1/me' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'Bad key k-123456']], 401)]);

    Livewire::test('pages::notifications.providers')
        ->call('testConnection', 'wacrm')
        ->assertDispatched('toast', message: 'unauthorized: Bad key ••••', variant: 'danger');
});

it('saves the sending hours and the date format of messages', function () {
    Livewire::test('pages::notifications.providers')
        ->set('notify_hours_from', '08:00')
        ->set('notify_hours_to', '20:30')
        ->set('notify_date_format', 'd/m/Y')
        ->set('notify_time_format', 'H:i')
        ->call('saveMessageSettings')
        ->assertHasNoErrors();

    expect(AppSetting::valueOf('notify_hours_to'))->toBe('20:30')
        ->and(AppSetting::valueOf('notify_time_format'))->toBe('H:i');

    Livewire::test('pages::notifications.providers')
        ->set('notify_hours_from', '22:00')
        ->set('notify_hours_to', '08:00')
        ->call('saveMessageSettings')
        ->assertHasErrors(['notify_hours_to']);
});

it('is only for roles that manage providers', function () {
    $this->actingAs(staffHolding(Permission::ManageNotificationRules, Permission::ViewNotificationLog))
        ->get(route('notifications.providers'))->assertForbidden();
});
