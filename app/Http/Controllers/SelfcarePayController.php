<?php

namespace App\Http\Controllers;

use App\Enums\OnlinePaymentOrigin;
use App\Enums\OnlinePaymentStatus;
use App\Http\Middleware\SelfcareSession;
use App\Models\OnlinePayment;
use App\Payments\GatewayStart;
use App\Support\PublicPage;
use Illuminate\View\View;

/**
 * The hand-off page of a payment the logged-in customer started in Selfcare (a form or script gateway). Only the
 * customer who owns a Pending Selfcare attempt sees it.
 */
class SelfcarePayController extends Controller
{
    public function __invoke(string $publicId): View
    {
        SelfcareSession::account() ?? abort(404);
        $payment = OnlinePayment::query()
            ->where('public_id', $publicId)
            ->where('origin', OnlinePaymentOrigin::Selfcare)
            ->where('status', OnlinePaymentStatus::Pending)
            ->firstOrFail();
        abort_unless(SelfcareSession::connections()->contains('id', $payment->account_id), 404);
        $start = GatewayStart::fromArray($payment->gateway_start ?? []) ?? abort(404);

        return view('pay.go', [...PublicPage::data(), 'start' => $start]);
    }
}
