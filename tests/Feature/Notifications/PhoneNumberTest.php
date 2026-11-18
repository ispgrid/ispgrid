<?php

use App\Enums\NotificationChannel;
use App\Models\Account;
use App\Models\AppSetting;
use App\Notifications\PhoneNumber;

it('turns what staff type into E.164', function (?string $raw, ?string $expected) {
    expect(PhoneNumber::toE164($raw, '+91', 10))->toBe($expected);
})->with([
    'ten digits' => ['9876543210', '+919876543210'],
    'leading zero and spaces' => ['098765 43210', '+919876543210'],
    'plus and dashes' => ['+91 98765-43210', '+919876543210'],
    'country code without plus' => ['919876543210', '+919876543210'],
    'double zero' => ['00919876543210', '+919876543210'],
    'another country with plus' => ['+14155550123', '+14155550123'],
    'too short' => ['12345', null],
    'letters' => ['98765abcde', null],
    'empty' => ['', null],
    'none' => [null, null],
]);

it('takes national numbers of any length in the range', function (string $raw, ?string $expected) {
    expect(PhoneNumber::toE164($raw, '+49', 10, 11))->toBe($expected);
})->with([
    'shortest' => ['1512345678', '+491512345678'],
    'longest' => ['15123456789', '+4915123456789'],
    'leading zero' => ['015123456789', '+4915123456789'],
    'country code without plus' => ['4915123456789', '+4915123456789'],
    'too short' => ['151234567', null],
]);

it('reads the range from the app settings', function () {
    AppSetting::put('phone_country_code', '+49');
    AppSetting::put('phone_digits', '11');
    AppSetting::put('phone_digits_min', '10');

    expect(PhoneNumber::fromSettings('1512345678'))->toBe('+491512345678')
        ->and(PhoneNumber::fromSettings('15123456789'))->toBe('+4915123456789');
});

it('uses the WhatsApp number for WhatsApp and the mobile for SMS', function () {
    $both = Account::factory()->make(['mobile' => '9876543210', 'whatsapp' => '9123456789']);
    $mobileOnly = Account::factory()->make(['mobile' => '9876543210', 'whatsapp' => null]);

    expect(PhoneNumber::rawFor($both, NotificationChannel::WhatsApp))->toBe('9123456789')
        ->and(PhoneNumber::rawFor($mobileOnly, NotificationChannel::WhatsApp))->toBe('9876543210')
        ->and(PhoneNumber::rawFor($both, NotificationChannel::Sms))->toBe('9876543210');
});
