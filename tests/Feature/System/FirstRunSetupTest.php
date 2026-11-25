<?php

use App\Billing\BillingSettings;
use App\Enums\Permission;
use App\Models\AppSetting;
use App\Models\Nas;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\RadiusSetting;
use App\Models\User;
use App\Support\Ipv4Block;
use App\Support\PhoneLength;
use App\Support\Setup\FirstRunSetup;
use App\Support\Setup\RouterCommands;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusSettingSeeder;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->seed(RadiusSettingSeeder::class);
    config(['radius.restart_command' => 'sudo systemctl restart freeradius', 'app.timezone' => 'Asia/Kolkata']);
    Process::fake();
    AppSetting::put(FirstRunSetup::PENDING, '1');
    $this->actingAs(User::factory()->create());
});

it('opens the setup page instead of the panel while a fresh install waits for it', function () {
    $this->get(route('dashboard'))->assertRedirect(route('setup'));
    $this->get(route('customers.index'))->assertRedirect(route('setup'));
    $this->get(route('setup'))->assertOk()->assertSee('Set up ISPGrid');
});

it('leaves the panel alone on installs that never waited for the setup page', function () {
    AppSetting::query()->where('key', FirstRunSetup::PENDING)->delete();

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('setup'))->assertRedirect(route('dashboard'));
});

it('lets staff who may not change the app settings use the panel meanwhile', function () {
    $this->actingAs(staffLacking(Permission::ManageAppSettings));

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('setup'))->assertForbidden();
});

it('starts from the country of the server timezone and fills its defaults', function () {
    config(['app.timezone' => 'Europe/Berlin']);

    Livewire::test('pages::system.setup')
        ->assertSet('country', 'DE')
        ->assertSet('currency_code', 'EUR')
        ->assertSet('date_format', 'd.m.Y')
        ->assertSet('phone_country_code', '+49')
        ->assertSet('phone_digits', '11')
        ->assertSet('phone_digits_min', '10')
        ->assertSet('number_grouping', 'international');
});

it('saves the country settings and offers UPI only in India', function () {
    Livewire::test('pages::system.setup')
        ->set('country', 'AE')
        ->assertSet('currency_code', 'AED')
        ->assertSet('phone_country_code', '+971')
        ->set('isp_name', 'Gulf Net')
        ->call('saveRegion')
        ->assertHasNoErrors()
        ->assertSet('step', 'nas');

    expect(AppSetting::valueOf('currency_code'))->toBe('AED')
        ->and(AppSetting::valueOf('phone_country_code'))->toBe('+971')
        ->and(PhoneLength::current())->toEqual(new PhoneLength(9, 9))
        ->and(AppSetting::valueOf('notice_isp_name'))->toBe('Gulf Net')
        ->and(BillingSettings::current()->company->country)->toBe('AE')
        ->and(BillingSettings::current()->company->name)->toBe('Gulf Net')
        ->and(PaymentMethod::query()->where('name', 'UPI')->sole()->active)->toBeFalse();
});

it('asks for the phone code of a country it does not know', function () {
    Livewire::test('pages::system.setup')
        ->set('country', 'IS')
        ->assertSet('currency_code', 'ISK')
        ->assertSet('phone_country_code', '')
        ->call('saveRegion')
        ->assertHasErrors(['phone_country_code']);
});

it('shows the full tunnel command for the NAS name typed and adds the NAS', function () {
    AppSetting::put(FirstRunSetup::ROUTERS, 'wireguard');
    AppSetting::put(FirstRunSetup::RADIUS_IP, '10.250.0.1');

    $page = Livewire::test('pages::system.setup')
        ->set('step', 'nas')
        ->set('shortname', 'office-nas')
        ->assertSee('sudo /opt/ispgrid/deploy/wireguard/wg-add-peer.sh office-nas');

    $page->set('nasname', '10.250.0.2')
        ->set('routeros_version', '7.19.4')
        ->call('saveNas')
        ->assertHasNoErrors()
        ->assertSee('/ppp aaa set use-radius=yes accounting=yes interim-update=5m');

    $nas = Nas::query()->sole();
    expect($nas->shortname)->toBe('office-nas')
        ->and($nas->nasname)->toBe('10.250.0.2')
        ->and($nas->secret)->toBe($page->get('secret'));
    Process::assertRan('sudo systemctl restart freeradius');
});

it('refuses a NAS name the tunnel script would not take', function () {
    AppSetting::put(FirstRunSetup::ROUTERS, 'l2tp');

    Livewire::test('pages::system.setup')
        ->set('step', 'nas')
        ->set('shortname', 'office nas')
        ->assertDontSee('l2tp-add-peer.sh')
        ->set('nasname', '10.251.0.2')
        ->set('routeros_version', '6.49.10')
        ->call('saveNas')
        ->assertHasErrors(['shortname']);
});

it('shows the RADIUS commands instead of a tunnel command on the same network', function () {
    AppSetting::put(FirstRunSetup::ROUTERS, 'lan');
    AppSetting::put(FirstRunSetup::RADIUS_IP, '192.168.88.10');

    Livewire::test('pages::system.setup')
        ->set('step', 'nas')
        ->set('shortname', 'office-nas')
        ->assertDontSee('add-peer.sh')
        ->set('nasname', '192.168.88.1')
        ->set('routeros_version', '7.19.4')
        ->call('saveNas')
        ->assertHasNoErrors()
        ->assertSee('/radius add address=192.168.88.10 service=ppp,hotspot');
});

it('builds the pool commands from the addresses typed and saves both pools', function () {
    AppSetting::put(FirstRunSetup::ROUTERS, 'wireguard');
    AppSetting::put(FirstRunSetup::RADIUS_IP, '10.250.0.1');

    Livewire::test('pages::system.setup')
        ->set('step', 'pools')
        ->set('pool_range', '10.10.0.0/22')
        ->assertSee('/ip pool add name=pppoe_pool ranges=10.10.0.2-10.10.3.254')
        ->assertSee('10.10.0.1')
        ->set('disabled_pool_name', 'expired_pool')
        ->set('disabled_range', '172.16.250.10-172.16.250.200')
        ->assertSee('src-address=172.16.250.10-172.16.250.200 protocol=tcp dst-port=80 action=dst-nat to-addresses=10.250.0.1 to-ports=8088')
        ->assertSee('src-address=172.16.250.10-172.16.250.200 protocol=udp dst-port=53 out-interface-list=WAN action=masquerade')
        ->assertDontSee('notice page replies')
        ->call('savePools')
        ->assertHasNoErrors()
        ->assertSet('step', 'plan');

    $pool = Pool::query()->where('name', 'pppoe_pool')->sole();
    expect(AppSetting::valueOf('default_pool_ppp'))->toBe((string) $pool->id)
        ->and($pool->description)->toContain('10.10.0.0/22')
        ->and(Pool::query()->where('name', 'expired_pool')->exists())->toBeTrue()
        ->and(RadiusSetting::current()->disabled_users_pool)->toBe('expired_pool');
    Process::assertRan('sudo systemctl restart freeradius');
});

it('adds the notice page reply rule on the same LAN, where no tunnel script added it', function () {
    AppSetting::put(FirstRunSetup::ROUTERS, 'lan');
    AppSetting::put(FirstRunSetup::RADIUS_IP, '10.2.2.112');

    Livewire::test('pages::system.setup')
        ->set('step', 'pools')
        ->set('disabled_range', '172.16.250.0/24')
        ->assertSee('/ip firewall nat add chain=srcnat dst-address=10.2.2.112 protocol=tcp dst-port=8088 action=masquerade');
});

it('refuses pools that share addresses or are not addresses', function () {
    Livewire::test('pages::system.setup')
        ->set('step', 'pools')
        ->set('pool_range', '10.10.0.0/22')
        ->set('disabled_range', '10.10.3.0/24')
        ->call('savePools')
        ->assertHasErrors(['disabled_range']);

    Livewire::test('pages::system.setup')
        ->set('step', 'pools')
        ->set('pool_range', 'ten dot ten')
        ->set('disabled_range', '172.16.250.0/24')
        ->call('savePools')
        ->assertHasErrors(['pool_range']);

    expect(Pool::query()->count())->toBe(0);
});

it('creates the first plan on the default pool', function () {
    $pool = Pool::factory()->create();
    AppSetting::put('default_pool_ppp', (string) $pool->id);

    Livewire::test('pages::system.setup')
        ->set('step', 'plan')
        ->set('plan_name', 'Home 50')
        ->set('download_speed', '50M')
        ->set('upload_speed', '25M')
        ->set('price', '599')
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSet('step', 'india');

    $plan = Plan::query()->sole();
    expect($plan->slug)->toBe('home_50')
        ->and($plan->pool_id)->toBe($pool->id)
        ->and($plan->validity)->toBe(30);
});

it('refuses a plan speed MikroTik would not read', function () {
    Livewire::test('pages::system.setup')
        ->set('step', 'plan')
        ->set('plan_name', 'Home 50')
        ->set('download_speed', '50 Mbps')
        ->set('upload_speed', '25M')
        ->set('price', '599')
        ->call('savePlan')
        ->assertHasErrors(['download_speed']);

    expect(Plan::query()->count())->toBe(0);
});

it('sets up GST invoices, UPI and two years of sessions for India', function () {
    Livewire::test('pages::system.setup')
        ->set('step', 'india')
        ->set('company_name', 'Linkio Broadband')
        ->set('gstin', '07abcde1234f1z5')
        ->assertSet('gst_state', '07')
        ->set('upi_id', 'linkio@okhdfcbank')
        ->call('saveIndia')
        ->assertHasNoErrors()
        ->assertSet('step', 'options');

    $billing = BillingSettings::current();
    expect($billing->enabled)->toBeTrue()
        ->and($billing->taxSetup)->toBe('india-gst')
        ->and($billing->taxConfig)->toBe(['rate' => '18', 'state' => '07', 'item_code' => '998422'])
        ->and($billing->company->taxNumber)->toBe('07ABCDE1234F1Z5')
        ->and($billing->company->state)->toBe('Delhi')
        ->and(AppSetting::valueOf('billing.upi_id'))->toBe('linkio@okhdfcbank')
        ->and(AppSetting::valueOf('session_history_days'))->toBe('730');
});

it('refuses a GSTIN from another state than the one chosen', function () {
    Livewire::test('pages::system.setup')
        ->set('step', 'india')
        ->set('company_name', 'Linkio Broadband')
        ->set('gst_state', '09')
        ->set('gstin', '07ABCDE1234F1Z5')
        ->call('saveIndia')
        ->assertHasErrors(['gstin']);
});

it('has no India step outside India', function () {
    Livewire::test('pages::system.setup')
        ->set('country', 'GB')
        ->set('step', 'plan')
        ->call('skip')
        ->assertSet('step', 'options');
});

it('finishes once, also when every step is skipped', function () {
    Livewire::test('pages::system.setup')
        ->call('skip')
        ->call('skip')
        ->call('finish', 'ip-ranges.index')
        ->assertRedirect(route('ip-ranges.index'));

    expect(FirstRunSetup::pending())->toBeFalse();
    $this->get(route('dashboard'))->assertOk();
});

it('opens the same step and country again after a reload', function () {
    Livewire::test('pages::system.setup')
        ->set('country', 'GB')
        ->set('phone_country_code', '+44')
        ->call('saveRegion')
        ->assertHasNoErrors()
        ->call('skip')
        ->assertSet('step', 'pools');

    Livewire::test('pages::system.setup')
        ->assertSet('step', 'pools')
        ->assertSet('country', 'GB')
        ->assertSet('done', ['region']);

    Livewire::test('pages::system.setup')->call('finish');

    expect(session('setup_progress'))->toBeNull();
});

it('finishes on the dashboard for any page it does not offer', function () {
    Livewire::test('pages::system.setup')->call('finish', 'staff.index')->assertRedirect(route('dashboard'));
});

it('saves what the installer tells it, and marks only fresh installs pending', function () {
    AppSetting::query()->where('key', FirstRunSetup::PENDING)->delete();

    $this->artisan('setup:server-info', ['--routers' => 'wireguard', '--radius-ip' => '10.250.0.1', '--home' => '/opt/ispgrid'])->assertSuccessful();

    expect(FirstRunSetup::routers())->toBe('wireguard')
        ->and(FirstRunSetup::radiusIp())->toBe('10.250.0.1')
        ->and(FirstRunSetup::pending())->toBeFalse();

    $this->artisan('setup:server-info', ['--pending' => true])->assertSuccessful();
    expect(FirstRunSetup::pending())->toBeTrue();

    $this->artisan('setup:server-info', ['--routers' => 'pigeon'])->assertFailed();
    $this->artisan('setup:server-info', ['--radius-ip' => 'not-an-ip'])->assertFailed();
});

it('keeps the address a subnet pool leaves for the NAS out of the pool', function (string $typed, string $range, ?string $local) {
    $block = Ipv4Block::parse($typed);

    expect(RouterCommands::poolRange($block))->toBe($range)
        ->and(RouterCommands::localAddress($block))->toBe($local);
})->with([
    'subnet' => ['10.10.0.0/24', '10.10.0.2-10.10.0.254', '10.10.0.1'],
    'range as typed' => ['10.10.0.5-10.10.0.9', '10.10.0.5-10.10.0.9', null],
    'tiny subnet' => ['10.10.0.0/31', '10.10.0.0-10.10.0.1', null],
]);

it('shows the PPPoE profile with the address a subnet leaves for the NAS, and the server once the port is typed', function () {
    Livewire::test('pages::system.setup')
        ->set('step', 'pools')
        ->set('pool_range', '10.10.0.0/22')
        ->assertSee('/ppp profile set default local-address=10.10.0.1 remote-address=pppoe_pool dns-server=8.8.8.8,1.1.1.1 change-tcp-mss=yes')
        ->assertDontSee('/interface pppoe-server')
        ->set('pppoe_interface', 'vlan100')
        ->assertSee('/interface pppoe-server server add service-name=ispgrid interface=vlan100 default-profile=default');
});

it('asks for the NAS address when the pool is a range, and keeps it out of the pools', function () {
    $page = Livewire::test('pages::system.setup')
        ->set('step', 'pools')
        ->set('pool_range', '10.10.0.2-10.10.3.254')
        ->assertDontSee('/ppp profile')
        ->set('gateway', '10.10.0.9')
        ->assertDontSee('/ppp profile')
        ->set('disabled_range', '172.16.250.0/24')
        ->call('savePools')
        ->assertHasErrors(['gateway']);

    $page->set('gateway', '10.255.255.1')
        ->assertSee('/ppp profile set default local-address=10.255.255.1 remote-address=pppoe_pool')
        ->call('savePools')
        ->assertHasNoErrors();
});

it('saves midnight expiry and Selfcare, and shows only the add-on commands ticked', function () {
    AppSetting::put('expiry_mode', 'exact');

    Livewire::test('pages::system.setup')
        ->set('step', 'options')
        ->assertSet('expiry_mode', 'midnight')
        ->assertSet('selfcare', false)
        ->assertDontSee('sudo ispgrid addon add')
        ->set('addons', ['documents'])
        ->assertSee('sudo ispgrid addon add ispgrid/documents')
        ->assertDontSee('sudo ispgrid addon add ispgrid/ipdr')
        ->set('selfcare', true)
        ->call('saveOptions')
        ->assertHasNoErrors()
        ->assertSet('step', 'finish');

    expect(AppSetting::valueOf('expiry_mode'))->toBe('midnight')
        ->and(AppSetting::valueOf('selfcare.enabled'))->toBe('1');
});

it('goes back to any step after the NAS is saved, and keeps the NAS after a reload', function () {
    AppSetting::put(FirstRunSetup::ROUTERS, 'lan');
    AppSetting::put(FirstRunSetup::RADIUS_IP, '192.168.88.10');

    $page = Livewire::test('pages::system.setup')
        ->set('step', 'nas')
        ->set('shortname', 'office-nas')
        ->set('nasname', '192.168.88.1')
        ->set('routeros_version', '7.19.4')
        ->call('saveNas')
        ->assertHasNoErrors()
        ->call('jump', 'region')
        ->assertSet('step', 'region')
        ->call('jump', 'nowhere')
        ->assertSet('step', 'region');

    Livewire::test('pages::system.setup')
        ->call('jump', 'nas')
        ->assertSet('nasId', Nas::query()->sole()->id)
        ->assertSet('secret', $page->get('secret'))
        ->assertSee('NAS office-nas saved.');
});
