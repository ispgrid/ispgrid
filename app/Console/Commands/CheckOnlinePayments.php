<?php

namespace App\Console\Commands;

use App\Actions\Payments\SettleOnlinePayment;
use App\Enums\OnlinePaymentStatus;
use App\Models\OnlinePayment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Quick pay attempts still Pending after 10 minutes (the customer closed the tab, the webhook was lost) are asked
 * about again; after a day without an answer they are Failed. One failing never stops the others.
 */
#[Signature('payments:check-online')]
#[Description('Ask the gateways about quick pay payments still pending')]
class CheckOnlinePayments extends Command
{
    public function handle(SettleOnlinePayment $settle): int
    {
        OnlinePayment::query()
            ->where('status', OnlinePaymentStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes(10))
            ->orderBy('id')
            ->each(function (OnlinePayment $payment) use ($settle): void {
                try {
                    $settled = $settle->handle($payment->public_id);
                } catch (Throwable $exception) {
                    report($exception);

                    return;
                }

                if ($settled->status === OnlinePaymentStatus::Pending && $settled->created_at?->lessThanOrEqualTo(now()->subDay())) {
                    $settled->update(['status' => OnlinePaymentStatus::Failed, 'note' => __('No answer from the gateway for a day.')]);
                }
            });

        return self::SUCCESS;
    }
}
