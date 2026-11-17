<?php

use App\Enums\OnlinePaymentOrigin;
use App\Models\OnlinePayment;
use App\Models\Payment;

it('defaults an attempt to the quick pay origin', function () {
    $attempt = OnlinePayment::factory()->create()->fresh();

    expect($attempt->origin)->toBe(OnlinePaymentOrigin::QuickPay)
        ->and($attempt->gateway_order_id)->toBeNull()
        ->and(Payment::factory()->create()->fresh()->proof_path)->toBeNull();
});
