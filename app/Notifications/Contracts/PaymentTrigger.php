<?php

namespace App\Notifications\Contracts;

use App\Models\Account;
use App\Notifications\PaymentNotice;

/**
 * A trigger fired for a payment: its messages go out once per payment, not once per recharge.
 */
interface PaymentTrigger
{
    public function paymentOnceKey(int $ruleId, Account $account, PaymentNotice $payment): string;
}
