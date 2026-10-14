<?php

use App\Models\User;
use Database\Seeders\RadiusAttributeSeeder;
use Database\Seeders\RadiusSettingSeeder;
use Livewire\Livewire;

/*
 * Every text field whose value reaches a FreeRADIUS table warns as soon as non-English text is typed. The server
 * refuses such text anyway (EnglishOnlyRowsTest); this keeps staff from finding out only on save.
 */

beforeEach(function () {
    $this->seed([RadiusAttributeSeeder::class, RadiusSettingSeeder::class]);
    $this->actingAs(User::factory()->create());
});

it('marks the fields that reach FreeRADIUS as English only', function (string $component, Closure $open, array $fields) {
    $html = $open(Livewire::test($component))->html();

    foreach ($fields as $field) {
        $id = 'field-'.str_replace('.', '-', $field);
        expect(preg_match('/<input[^>]*\bid="'.preg_quote($id, '/').'"[^>]*>/', $html, $input))->toBe(1, "{$component}: no input {$id}")
            ->and($input[0])->toContain('lang="en"');
    }
})->with([
    'customer' => ['pages::customers.form', fn ($page) => $page->set('override_plan', true)->call('addAttribute'), [
        'username', 'password', 'ip_address', 'override_upload', 'override_download', 'override_burst_upload',
        'override_burst_download', 'override_threshold_upload', 'override_threshold_download', 'override_min_upload',
        'override_min_download', 'custom_attributes.0.value',
    ]],
    'plan' => ['pages::plans.form', fn ($page) => $page->set('is_fup', true)->set('slot_mode', 'boost')->call('addAttribute'), [
        'slug', 'upload_speed', 'download_speed', 'burst_upload', 'burst_download', 'threshold_upload',
        'threshold_download', 'min_upload', 'min_download', 'fup_upload', 'fup_download', 'slot_upload',
        'slot_download', 'custom_attributes.0.value',
    ]],
    'router' => ['pages::network.routers', fn ($page) => $page->call('create'), ['shortname', 'nasname', 'secret']],
    'pool' => ['pages::network.pools', fn ($page) => $page->call('create'), ['name']],
    'address list' => ['pages::network.address-lists', fn ($page) => $page->call('create'), ['name']],
    'IP range' => ['pages::network.ip-ranges', fn ($page) => $page->call('create'), ['network']],
    'FreeRADIUS settings' => ['pages::system.radius-settings', fn ($page) => $page, ['disabled_users_pool']],
]);
