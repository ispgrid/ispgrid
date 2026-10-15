<?php

use App\Models\AppSetting;
use App\Models\IpRange;
use App\Models\Pool;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->actingAs(User::factory()->create());
});

it('shows the saved settings and the server timezone', function () {
    config(['app.timezone' => 'Asia/Kolkata']);

    Livewire::test('pages::system.app-settings')
        ->assertSet('currency_code', 'INR')
        ->assertSet('expiry_mode', 'exact')
        ->assertSee('Asia/Kolkata');
});

it('saves the settings and records each change', function () {
    Livewire::test('pages::system.app-settings')
        ->set('currency_code', 'USD')
        ->set('currency_symbol', '$')
        ->set('number_grouping', 'international')
        ->set('expiry_mode', 'midnight')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Settings saved.');

    expect(AppSetting::valueOf('currency_code'))->toBe('USD')
        ->and(AppSetting::valueOf('expiry_mode'))->toBe('midnight')
        ->and(Activity::query()->where('event', 'updated')->count())->toBe(4);
});

it('saves nothing when one field is invalid', function () {
    Livewire::test('pages::system.app-settings')
        ->set('currency_code', 'USD')
        ->set('currency_decimals', '7')
        ->call('save')
        ->assertHasErrors(['currency_decimals']);

    expect(AppSetting::valueOf('currency_code'))->toBe('INR');
});

it('rejects values outside the allowed lists', function (string $field, string $value) {
    Livewire::test('pages::system.app-settings')
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field]);
})->with([
    'unknown grouping' => ['number_grouping', 'chinese'],
    'unknown date format' => ['date_format', 'Y/d/m'],
    'unknown expiry mode' => ['expiry_mode', 'weekly'],
    'phone code without plus' => ['phone_country_code', '91'],
    'lowercase currency' => ['currency_code', 'usd'],
    'too many phone digits' => ['phone_digits', '16'],
    'empty notice message' => ['notice_message', ''],
    'language the panel does not ship' => ['locale', 'fr'],
    'login log kept under a week' => ['login_log_days', '6'],
    'login log kept over ten years' => ['login_log_days', '3651'],
    'login log days not a number' => ['login_log_days', 'ninety'],
]);

it('accepts an empty customer ID label, which hides that field', function () {
    Livewire::test('pages::system.app-settings')
        ->set('business_id_label', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::valueOf('business_id_label'))->toBe('');
});

it('links the System menu to app settings', function () {
    $this->get(route('dashboard'))->assertSee(route('app-settings.edit'));
});

it('saves the default pool of each customer type and the default IPoE range', function () {
    $pppoe = Pool::factory()->create();
    $hotspot = Pool::factory()->create();
    $range = IpRange::factory()->create();

    Livewire::test('pages::system.app-settings')
        ->set('default_pool_ppp', (string) $pppoe->id)
        ->set('default_pool_hotspot', (string) $hotspot->id)
        ->set('default_ip_range_ipoe', (string) $range->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::valueOf('default_pool_ppp'))->toBe((string) $pppoe->id)
        ->and(AppSetting::valueOf('default_pool_hotspot'))->toBe((string) $hotspot->id)
        ->and(AppSetting::valueOf('default_ip_range_ipoe'))->toBe((string) $range->id);
});

it('refuses a deleted pool or range as a default', function () {
    Livewire::test('pages::system.app-settings')
        ->set('default_pool_ppp', (string) Pool::factory()->trashed()->create()->id)
        ->set('default_ip_range_ipoe', (string) IpRange::factory()->trashed()->create()->id)
        ->call('save')
        ->assertHasErrors(['default_pool_ppp', 'default_ip_range_ipoe']);
});

it('saves how many days the login log is kept', function () {
    Livewire::test('pages::system.app-settings')
        ->assertSet('login_log_days', '90')
        ->assertSee(__('Keep login log (days)'))
        ->set('login_log_days', '365')
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::valueOf('login_log_days'))->toBe('365');
});

it('shows 90 days on an install whose settings predate the login log setting', function () {
    AppSetting::query()->where('key', 'login_log_days')->delete();

    Livewire::test('pages::system.app-settings')
        ->assertSet('login_log_days', '90')
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::valueOf('login_log_days'))->toBe('90');
});
