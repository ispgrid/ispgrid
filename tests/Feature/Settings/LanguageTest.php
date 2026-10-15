<?php

use App\Models\AppSetting;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusSettingSeeder;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->seed(RadiusSettingSeeder::class);
});

it('starts in English on a new install', function () {
    expect(AppSetting::valueOf('locale'))->toBe('en');

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee('<html lang="en">', false);
});

it('shows the panel in the app language when the staff member has none', function () {
    AppSetting::put('locale', 'hi');

    $this->actingAs(User::factory()->create(['locale' => null]))
        ->get(route('dashboard'))
        ->assertSee('<html lang="hi">', false)
        ->assertSee(trans('Dashboard', [], 'hi'));
});

it('lets the staff member\'s own language win over the app language', function () {
    AppSetting::put('locale', 'hi');

    $this->actingAs(User::factory()->create(['locale' => 'en']))
        ->get(route('dashboard'))
        ->assertSee('<html lang="en">', false);
});

it('falls back to English for a language the panel does not ship', function () {
    AppSetting::put('locale', 'fr');

    $this->actingAs(User::factory()->create(['locale' => 'de']))
        ->get(route('dashboard'))
        ->assertSee('<html lang="en">', false);
});

it('shows the login page and the notice page in the app language', function (string $path) {
    AppSetting::put('locale', 'hi');

    $this->get($path)->assertSee('<html lang="hi">', false);
})->with(['/login', '/notice']);

it('translates the notice page text', function () {
    AppSetting::put('locale', 'hi');

    $this->get('/notice')->assertSee(trans('Internet access comes back as soon as your account is renewed.', [], 'hi'));
});

it('saves the staff member\'s language and reloads the page in it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->assertSet('locale', '')
        ->set('locale', 'hi')
        ->call('updateLanguage')
        ->assertHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->locale)->toBe('hi');
});

it('goes back to the panel default when the staff member clears the language', function () {
    $user = User::factory()->create(['locale' => 'hi']);

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->assertSet('locale', 'hi')
        ->set('locale', '')
        ->call('updateLanguage')
        ->assertHasNoErrors();

    expect($user->fresh()->locale)->toBeNull();
});

it('refuses a language the panel does not ship on the profile', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.profile')
        ->set('locale', 'fr')
        ->call('updateLanguage')
        ->assertHasErrors(['locale']);

    expect($user->fresh()->locale)->toBeNull();
});

it('shows Laravel\'s validation messages in Hindi, with the field named as the form labels it', function () {
    App::setLocale('hi');

    Livewire::actingAs(User::factory()->create())->test('pages::system.app-settings')
        ->set('currency_code', '')
        ->call('save')
        ->assertHasErrors(['currency_code' => 'मुद्रा कोड ज़रूरी है।']);
});

it('reloads App settings when the app language changes', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::system.app-settings')
        ->assertSet('locale', 'en')
        ->set('locale', 'hi')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('app-settings.edit'));

    expect(AppSetting::valueOf('locale'))->toBe('hi');
});
