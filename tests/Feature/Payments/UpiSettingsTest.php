<?php

use App\Enums\Permission;
use App\Models\AppSetting;
use App\Payments\UpiDetails;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('billing');
    turnInvoicingOn();
    AppSetting::put('currency_code', 'INR');
    $this->actingAs(staffHolding(Permission::ManageBilling));
});

function upiPage()
{
    return Livewire::test('pages::system.online-payments');
}

it('saves the UPI ID, number, note and QR for an INR panel', function () {
    upiPage()
        ->set('upi_id', 'shop@okbank')
        ->set('upi_number', '+91 98765 43210')
        ->set('upi_note', 'Send the screenshot to our desk.')
        ->set('upi_qr', UploadedFile::fake()->image('qr.png', 40, 40))
        ->call('saveUpi')
        ->assertHasNoErrors();

    $qr = AppSetting::valueOf('billing.upi_qr');

    expect(AppSetting::valueOf('billing.upi_id'))->toBe('shop@okbank')
        ->and(AppSetting::valueOf('billing.upi_number'))->toBe('+91 98765 43210')
        ->and(AppSetting::valueOf('billing.upi_note'))->toBe('Send the screenshot to our desk.')
        ->and($qr)->toMatch('#^upi/qr-[A-Za-z0-9]{16}\.png$#')
        ->and(Activity::query()->where('event', 'upi-settings')->count())->toBe(1);
    Storage::disk('billing')->assertExists($qr);
});

it('deletes the old QR file when a new one is saved or the picture is removed', function () {
    $page = upiPage()->set('upi_qr', UploadedFile::fake()->image('a.png', 40, 40))->call('saveUpi');
    $first = AppSetting::valueOf('billing.upi_qr');

    $page->set('upi_qr', UploadedFile::fake()->image('b.jpg', 40, 40))->call('saveUpi')->assertHasNoErrors();
    $second = AppSetting::valueOf('billing.upi_qr');

    expect($second)->not->toBe($first)->and($second)->toEndWith('.jpg');
    Storage::disk('billing')->assertMissing($first);
    Storage::disk('billing')->assertExists($second);

    $page->call('removeUpiQr');

    expect(AppSetting::valueOf('billing.upi_qr'))->toBe('');
    Storage::disk('billing')->assertMissing($second);
});

it('rejects a UPI ID that is not name@handle, a bad number and a disguised QR', function () {
    upiPage()
        ->set('upi_id', 'not a upi id')
        ->set('upi_number', 'call me')
        ->set('upi_qr', UploadedFile::fake()->createWithContent('qr.png', '<html><script>alert(1)</script></html>'))
        ->call('saveUpi')
        ->assertHasErrors(['upi_id', 'upi_number', 'upi_qr']);

    expect(AppSetting::valueOf('billing.upi_qr'))->toBeNull();
});

it('is for staff with Billing settings only', function () {
    $this->actingAs(staffHolding(Permission::ViewInvoices));

    Livewire::test('pages::system.online-payments')->assertForbidden();
});

it('hides the UPI section and refuses saveUpi when the currency is USD', function () {
    AppSetting::put('currency_code', 'USD');

    upiPage()->assertDontSee(__('UPI ID'))->set('upi_id', 'shop@okbank')->call('saveUpi')->assertForbidden();
    upiPage()->call('removeUpiQr')->assertForbidden();

    expect(AppSetting::valueOf('billing.upi_id'))->toBeNull();
});

it('keeps the saved UPI values when the currency changes and shows them again with INR', function () {
    AppSetting::put('billing.upi_id', 'shop@okbank');
    AppSetting::put('billing.upi_number', '9876543210');

    expect(UpiDetails::current()?->id)->toBe('shop@okbank');

    AppSetting::put('currency_code', 'USD');

    expect(UpiDetails::current())->toBeNull()
        ->and(AppSetting::valueOf('billing.upi_id'))->toBe('shop@okbank');

    AppSetting::put('currency_code', 'INR');

    expect(UpiDetails::current()?->number)->toBe('9876543210');
    upiPage()->assertSet('upi_id', 'shop@okbank')->assertSee(__('UPI ID'));
});

it('has no details when nothing is set', function () {
    AppSetting::put('billing.upi_note', 'Only a note');

    expect(UpiDetails::current())->toBeNull();
});

it('serves only the current QR and only for INR', function () {
    upiPage()->set('upi_qr', UploadedFile::fake()->image('a.png', 40, 40))->call('saveUpi');
    $old = basename((string) AppSetting::valueOf('billing.upi_qr'));
    upiPage()->set('upi_qr', UploadedFile::fake()->image('b.png', 40, 40))->call('saveUpi');
    $current = basename((string) AppSetting::valueOf('billing.upi_qr'));
    auth()->logout();

    $this->get(route('pay.upi-qr', $current))->assertOk()->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'max-age=86400, public');
    $this->get(route('pay.upi-qr', $old))->assertNotFound();
    $this->get('/pay/upi-qr/..%2F..%2F.env')->assertNotFound();
    $this->get('/pay/upi-qr/qr-AAAAAAAAAAAAAAAA.png')->assertNotFound();

    AppSetting::put('currency_code', 'USD');
    $this->get(route('pay.upi-qr', $current))->assertNotFound();
});

it('builds the default note from the notice phone', function () {
    AppSetting::put('billing.upi_id', 'shop@okbank');
    AppSetting::put('notice_phone', '+91 99999 11111');

    expect(UpiDetails::current()?->note)->toBe('After paying, send the screenshot on WhatsApp to +91 99999 11111.');

    AppSetting::put('billing.upi_note', 'My own line');
    expect(UpiDetails::current()?->note)->toBe('My own line');

    AppSetting::put('billing.upi_note', '');
    AppSetting::put('notice_phone', '');
    expect(UpiDetails::current()?->note)->toBe('');
});

it('turns UPI off without losing the details, and on only with something to pay to', function () {
    upiPage()->set('upi_enabled', true)->call('saveUpi')->assertHasErrors('upi_enabled');

    expect(AppSetting::valueOf(UpiDetails::SWITCH))->toBeNull();

    upiPage()->set('upi_enabled', true)->set('upi_id', 'shop@okbank')->call('saveUpi')->assertHasNoErrors();

    expect(UpiDetails::current()?->id)->toBe('shop@okbank');

    upiPage()->assertSet('upi_enabled', true)->set('upi_enabled', false)->call('saveUpi')->assertHasNoErrors();

    expect(UpiDetails::current())->toBeNull()
        ->and(AppSetting::valueOf('billing.upi_id'))->toBe('shop@okbank');
    upiPage()->assertSet('upi_enabled', false);
});

it('keeps UPI on for a panel saved before the switch existed', function () {
    AppSetting::put('billing.upi_id', 'shop@okbank');

    expect(UpiDetails::current()?->id)->toBe('shop@okbank');
    upiPage()->assertSet('upi_enabled', true);
});
