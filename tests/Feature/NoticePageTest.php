<?php

use App\Models\AppSetting;
use App\Support\NoticePage;
use Illuminate\Support\Facades\App;

beforeEach(function () {
    AppSetting::put('notice_isp_name', 'Linkio Broadband');
    AppSetting::put('notice_phone', '+91 98765 43210');
    AppSetting::put('notice_message', 'Your account is inactive or expired. Please contact us to renew.');
});

it('shows the ISP notice on any path and query, without a login', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertSee('Linkio Broadband')
        ->assertSee('Your account is inactive or expired. Please contact us to renew.')
        ->assertSee('href="tel:+919876543210"', false)
        ->assertHeader('Cache-Control', 'no-store, private');
})->with(['/notice', '/notice/generate_204', '/notice/hotspot/login?dst=http%3A%2F%2Fexample.com%2F']);

it('sets no cookie', function () {
    expect($this->get('/notice/anything')->headers->getCookies())->toBe([]);
});

it('escapes the notice text', function () {
    AppSetting::put('notice_message', '<script>alert(1)</script>');

    $this->get('/notice')->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
});

it('builds the IPoE login page with the ISP name and phone, in the panel language', function () {
    AppSetting::put('locale', 'hi');
    App::setLocale('en');

    $html = NoticePage::ipoeLoginPage();

    expect($html)
        ->toContain('Linkio Broadband')
        ->toContain('href="tel:+919876543210"')
        ->toContain('<html lang="hi">')
        ->toContain(__('This connection is not set up correctly', [], 'hi'))
        ->not->toContain('This connection is not set up correctly')
        ->and(App::getLocale())->toBe('en');
});

it('keeps the notice text in the IPoE login page from being read as a RouterOS variable', function () {
    AppSetting::put('notice_isp_name', 'Net $(username) <b>');

    expect(NoticePage::ipoeLoginPage())
        ->toContain('Net $&#40;username) &lt;b&gt;')
        ->not->toContain('$(username)');
});
