<?php

use App\Enums\NotificationChannel;
use App\Models\Account;
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

it('uses the WhatsApp number for WhatsApp and the mobile for SMS', function () {
    $both = Account::factory()->make(['mobile' => '9876543210', 'whatsapp' => '9123456789']);
    $mobileOnly = Account::factory()->make(['mobile' => '9876543210', 'whatsapp' => null]);

    expect(PhoneNumber::rawFor($both, NotificationChannel::WhatsApp))->toBe('9123456789')
        ->and(PhoneNumber::rawFor($mobileOnly, NotificationChannel::WhatsApp))->toBe('9876543210')
        ->and(PhoneNumber::rawFor($both, NotificationChannel::Sms))->toBe('9876543210');
});
