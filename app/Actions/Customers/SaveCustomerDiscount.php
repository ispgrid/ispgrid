<?php

namespace App\Actions\Customers;

use App\Billing\Discount;
use App\Models\Account;
use App\Support\Currency;

/**
 * Saves a discount as the customer's own, taken off every later recharge, and logs it as a billing change. Called
 * inside the caller's transaction (a recharge with "Keep this discount for future renewals").
 */
final class SaveCustomerDiscount
{
    public function handle(Account $account, Discount $discount): void
    {
        $old = self::text($account);
        $amount = $discount->isAmount() && ! $discount->isNone();

        $account->forceFill([
            'discount_type' => $amount ? Discount::AMOUNT : Discount::PERCENT,
            'discount_percent' => $amount || $discount->isNone() ? '0' : $discount->value,
            'discount_amount' => $amount ? $discount->value : '0',
        ])->save();

        $new = self::text($account);

        if ($new === $old) {
            return;
        }

        activity()
            ->performedOn($account)
            ->event('billing-changed')
            ->withChanges(['old' => ['discount' => $old], 'attributes' => ['discount' => $new]])
            ->log('billing-changed');
    }

    /**
     * The customer's discount as the activity log shows it: "10%", "100.00", or "0" for none.
     */
    public static function text(Account $account): string
    {
        return Discount::forAccount($account)->logText(Currency::decimals());
    }
}
