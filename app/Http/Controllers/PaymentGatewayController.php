<?php

namespace App\Http\Controllers;

use App\Actions\Payments\SettleOnlinePayment;
use App\Payments\PaymentGateways;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Where gateways send the customer back (return) and tell the server (webhook). Each request is first checked by
 * the gateway; then the payment is settled, which asks the gateway again server to server. Works while quick pay is
 * off too, so a payment already made is never stranded.
 */
class PaymentGatewayController extends Controller
{
    public function return(Request $request, string $gateway, PaymentGateways $gateways, SettleOnlinePayment $settle): RedirectResponse
    {
        $publicId = $gateways->make($gateway)?->verify($request);

        if ($publicId === null) {
            return redirect()->route('pay.show')->with('pay_message', __('We could not confirm that payment here. If money was taken, it is confirmed within a few minutes.'));
        }

        try {
            $settle->handle($publicId);
        } catch (Throwable $exception) {
            report($exception); // the scheduled check settles it later
        }

        return redirect()->route('pay.result', $publicId);
    }

    public function webhook(Request $request, string $gateway, PaymentGateways $gateways, SettleOnlinePayment $settle): Response
    {
        $publicId = $gateways->make($gateway)?->verify($request);
        abort_if($publicId === null, 404);

        $settle->handle($publicId);

        return response('OK');
    }
}
