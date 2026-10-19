<?php

use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\IpRange;
use App\Models\Pool;
use App\Models\RadiusSetting;
use App\Models\User;
use App\Support\Hotspot;
use App\Support\NoticePage;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusSettingSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->seed(RadiusSettingSeeder::class);
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
    'session history under a month' => ['session_history_days', '30'],
    'session history over ten years' => ['session_history_days', '3651'],
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

it('offers and takes only IPoE ranges as the IPoE default', function () {
    $ipoe = IpRange::factory()->create();
    $pppoe = IpRange::factory()->pppoe()->create();

    $page = Livewire::test('pages::system.app-settings');
    expect(array_keys($page->instance()->ipRanges))->toBe([$ipoe->id]);

    $page->set('default_ip_range_ipoe', (string) $pppoe->id)->call('save')->assertHasErrors(['default_ip_range_ipoe']);
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

it('turns Hotspot on and off in the FreeRADIUS settings, and shows the Hotspot pool only while it is on', function () {
    Livewire::test('pages::system.app-settings')
        ->assertSet('hotspot_enabled', false)
        ->assertDontSee('Pool for Hotspot customers')
        ->set('hotspot_enabled', true)
        ->assertSee('Pool for Hotspot customers')
        ->call('save')
        ->assertHasNoErrors();

    expect(RadiusSetting::current()->hotspot_enabled)->toBeTrue()->and(Hotspot::enabled())->toBeTrue();

    Livewire::test('pages::system.app-settings')
        ->assertSet('hotspot_enabled', true)
        ->set('hotspot_enabled', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(Hotspot::enabled())->toBeFalse();
});

it('refuses to turn Hotspot off while Hotspot customers exist, and saves nothing', function () {
    turnHotspotOn();
    Account::factory()->count(2)->hotspot()->create();

    Livewire::test('pages::system.app-settings')
        ->set('currency_code', 'USD')
        ->set('hotspot_enabled', false)
        ->call('save')
        ->assertHasErrors(['hotspot_enabled'])
        ->assertSee('2 Hotspot customers exist. Delete them or change their type first.');

    expect(Hotspot::enabled())->toBeTrue()
        ->and(AppSetting::valueOf('currency_code'))->toBe('INR');
});

it('saves the settings on a server without the FreeRADIUS settings row while Hotspot stays off', function () {
    RadiusSetting::query()->delete();

    Livewire::test('pages::system.app-settings')
        ->set('currency_code', 'USD')
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::valueOf('currency_code'))->toBe('USD');
});

it('refuses to turn Hotspot on without the FreeRADIUS settings row, and saves nothing', function () {
    RadiusSetting::query()->delete();

    Livewire::test('pages::system.app-settings')
        ->set('currency_code', 'USD')
        ->set('hotspot_enabled', true)
        ->call('save')
        ->assertHasErrors(['hotspot_enabled'])
        ->assertSee('The FreeRADIUS settings are missing. Run the installer again to restore them.');

    expect(AppSetting::valueOf('currency_code'))->toBe('INR');
});

it('turns Hotspot off when only deleted Hotspot customers are left', function () {
    turnHotspotOn();
    Account::factory()->hotspot()->trashed()->create();

    Livewire::test('pages::system.app-settings')->set('hotspot_enabled', false)->call('save')->assertHasNoErrors();

    expect(Hotspot::enabled())->toBeFalse();
});

it('offers Hotspot customers only while Hotspot is on', function () {
    expect(Hotspot::serviceTypes())->toBe([ServiceType::Ppp, ServiceType::Ipoe]);

    turnHotspotOn();

    expect(Hotspot::serviceTypes())->toBe([ServiceType::Ppp, ServiceType::Hotspot, ServiceType::Ipoe]);
});

it('saves how many days the session history is kept', function () {
    Livewire::test('pages::system.app-settings')
        ->assertSet('session_history_days', '365')
        ->assertSee(__('Keep session history (days)'))
        ->set('session_history_days', '400')
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::valueOf('session_history_days'))->toBe('400');
});

it('shows 365 days on an install whose settings predate the session history setting', function () {
    AppSetting::query()->where('key', 'session_history_days')->delete();

    Livewire::test('pages::system.app-settings')
        ->assertSet('session_history_days', '365')
        ->call('save')
        ->assertHasNoErrors();

    expect(AppSetting::valueOf('session_history_days'))->toBe('365');
});

it('leaves the add-on list to the Add-ons page', function () {
    Livewire::test('pages::system.app-settings')->assertDontSee(__('No add-ons installed.'));
});

it('downloads the IPoE login page from the saved notice settings', function () {
    AppSetting::put('notice_isp_name', 'Linkio Broadband');

    Livewire::test('pages::system.app-settings')
        ->set('notice_isp_name', 'Not saved yet')
        ->call('downloadIpoeLoginPage')
        ->assertFileDownloaded('login.html', NoticePage::ipoeLoginPage(), 'text/html; charset=utf-8');

    expect(NoticePage::ipoeLoginPage())->toContain('Linkio Broadband')->not->toContain('Not saved yet');
});
