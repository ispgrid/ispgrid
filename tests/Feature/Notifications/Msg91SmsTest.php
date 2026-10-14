<?php

use App\Enums\NotificationChannel;
use App\Notifications\OutgoingMessage;
use App\Notifications\Providers\Msg91Sms;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends a DLT template with numbered variables and the number without plus', function () {
    Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'message' => 'req-1'])]);

    (new Msg91Sms(['auth_key' => 'k1']))->send(new OutgoingMessage('+919876543210', ['template_id' => 'tmpl', 'variables' => ''], ['Asha', '22-Aug-2026'], null, '5'));

    expect(Msg91Sms::channel())->toBe(NotificationChannel::Sms);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://control.msg91.com/api/v5/flow'
        && $request->hasHeader('authkey', 'k1')
        && $request->data() === [
            'template_id' => 'tmpl',
            'short_url' => '0',
            'recipients' => [['mobiles' => '919876543210', 'VAR1' => 'Asha', 'VAR2' => '22-Aug-2026']],
        ]);
});

it('uses the variable names the template has', function () {
    Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'message' => 'req-1'])]);

    (new Msg91Sms(['auth_key' => 'k1']))->send(new OutgoingMessage('+919876543210', ['template_id' => 't', 'variables' => 'name, date'], ['Asha', '22-Aug-2026', 'extra'], null, '5'));

    Http::assertSent(fn (Request $request): bool => $request->data()['recipients'][0] === [
        'mobiles' => '919876543210', 'name' => 'Asha', 'date' => '22-Aug-2026', 'VAR3' => 'extra',
    ]);
});

it('reads an error answered with status 200 as a refusal', function () {
    Http::fake(['control.msg91.com/*' => Http::response(['type' => 'error', 'message' => 'Template ID is missing'])]);

    $result = (new Msg91Sms(['auth_key' => 'k1']))->send(new OutgoingMessage('+919876543210', ['template_id' => 't'], [], null, '1'));

    expect($result->sent)->toBeFalse()->and($result->reason)->toBe('Template ID is missing')->and($result->retryable)->toBeFalse();
});
