<?php

use App\Actions\Customers\SetSelfcarePassword;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\PasswordPattern;
use App\Support\Selfcare;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusSettingSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->seed(RadiusSettingSeeder::class);
    AppSetting::put('phone_digits', '10');
    $this->actingAs(User::factory()->create());
});

it('makes a password from the pattern', function () {
    AppSetting::put(PasswordPattern::SETTING, '{first_name}{mobile_last4}');
    expect(PasswordPattern::make('Ravi Kumar', '9876543210'))->toBe('ravi3210');

    AppSetting::put(PasswordPattern::SETTING, '{first_name}@{digits}');
    expect(PasswordPattern::make('  Ravi Kumar', null))->toMatch('/^ravi@\d{4}$/');
});

it('falls back to random for a Hindi name, a missing mobile or a short result', function (string $pattern, ?string $name, ?string $mobile) {
    AppSetting::put(PasswordPattern::SETTING, $pattern);

    expect(PasswordPattern::make($name, $mobile))->toBeNull();
})->with([
    'Hindi name' => ['{first_name}{digits}', 'रवि कुमार', '9876543210'],
    'no name' => ['{first_name}{digits}', '', '9876543210'],
    'short mobile' => ['net{mobile_last4}', 'Ravi', '987'],
    'under 6 characters' => ['{first_name}', 'Al Bo', null],
]);

it('gives no password while no pattern is set', function () {
    expect(PasswordPattern::make('Ravi Kumar', '9876543210'))->toBeNull();
});

it('saves a pattern and refuses an unknown token or a quote', function (string $pattern, bool $valid) {
    $page = Livewire::test('pages::system.app-settings')->set('password_pattern', $pattern)->call('save');

    $valid ? $page->assertHasNoErrors() : $page->assertHasErrors(['password_pattern']);
    expect(AppSetting::valueOf(PasswordPattern::SETTING))->toBe($valid ? $pattern : null);
})->with([
    ['{first_name}{digits}', true],
    ['net-{mobile_last4}', true],
    ['{last_name}', false],
    ["{first_name}'1", false],
    ['{first_name} 12', false],
]);

it('pre-fills a new customer password from the pattern until staff type their own', function () {
    AppSetting::put(PasswordPattern::SETTING, '{first_name}{mobile_last4}');

    $form = Livewire::test('pages::customers.form')
        ->set('fullname', 'Ravi Kumar')
        ->set('mobile', '9876543210')
        ->assertSet('password', 'ravi3210')
        ->set('fullname', 'Sita Rao')
        ->assertSet('password', 'sita3210')
        ->set('password', 'mine-123')
        ->set('fullname', 'Gita Rao')
        ->assertSet('password', 'mine-123');

    $form->set('username', 'gita')->call('save')->assertHasNoErrors();
    expect(Account::query()->where('username', 'gita')->value('password'))->toBe('mine-123');
});

it('pre-fills nothing without a pattern', function () {
    Livewire::test('pages::customers.form')
        ->set('fullname', 'Ravi Kumar')
        ->set('mobile', '9876543210')
        ->assertSet('password', '');
});

it('does not force a change of a selfcare password made from the pattern', function () {
    AppSetting::put(PasswordPattern::SETTING, '{first_name}{mobile_last4}');
    $account = Account::factory()->create(['fullname' => 'Ravi Kumar', 'mobile' => '9876543210']);

    $password = app(SetSelfcarePassword::class)->handle($account);
    $account->refresh();

    expect($password)->toBe('ravi3210')
        ->and(Hash::check('ravi3210', $account->selfcare_password))->toBeTrue()
        ->and($account->selfcare_must_change)->toBeFalse()
        ->and(Selfcare::details($account, $password))->not->toContain('Choose your own password');
});

it('still forces a change of a typed or random selfcare password', function () {
    $account = Account::factory()->create(['fullname' => 'रवि', 'mobile' => '9876543210']);
    AppSetting::put(PasswordPattern::SETTING, '{first_name}{digits}');

    app(SetSelfcarePassword::class)->handle($account);
    expect($account->refresh()->selfcare_must_change)->toBeTrue();

    $account->forceFill(['selfcare_must_change' => false])->save();
    app(SetSelfcarePassword::class)->handle($account, 'Typed-pass-1');
    expect($account->refresh()->selfcare_must_change)->toBeTrue()
        ->and(Selfcare::details($account, 'Typed-pass-1'))->toContain('Choose your own password');
});

it('drops the pattern password when staff switch a new customer to IPoE, and fills it again on the way back', function () {
    AppSetting::put(PasswordPattern::SETTING, '{first_name}{mobile_last4}');

    Livewire::test('pages::customers.form')
        ->set('fullname', 'Ravi Kumar')
        ->set('mobile', '9876543210')
        ->set('service_type', 'IPOE')
        ->assertSet('password', '')
        ->set('service_type', 'PPP')
        ->assertSet('password', 'ravi3210');
});
