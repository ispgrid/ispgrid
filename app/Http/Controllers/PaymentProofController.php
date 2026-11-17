<?php

namespace App\Http\Controllers;

use App\Billing\PaymentProof;
use App\Models\Payment;
use App\Support\Addons\PrivateFile;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The screenshot kept with a manual payment, for staff with View invoices. A picture opens in the browser (judged by
 * its bytes); a PDF and anything else downloads, so a PDF with script never runs at the panel's address. Never linked from Selfcare, the receipt or its PDF.
 */
class PaymentProofController extends Controller
{
    public function __invoke(Payment $payment): StreamedResponse
    {
        abort_unless(Gate::allows('view-invoices'), 403);
        abort_if($payment->proof_path === null, 404);

        return PrivateFile::response(
            $payment->proof_path,
            'payment-'.$payment->id.'-proof.'.pathinfo($payment->proof_path, PATHINFO_EXTENSION),
            inline: strtolower(pathinfo($payment->proof_path, PATHINFO_EXTENSION)) !== 'pdf',
            disk: PaymentProof::DISK,
        );
    }
}
