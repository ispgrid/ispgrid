<?php

namespace App\Http\Controllers;

use App\Billing\Pdf\ReceiptPdf;
use App\Billing\Pdf\ReceiptView;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The receipt PDF of a payment, built now (never stored), for staff with View invoices. A removed payment's receipt
 * still opens, marked removed. ?print=1 opens it in the browser to print; otherwise it downloads. Each one is logged
 * on the customer.
 */
class PaymentReceiptController extends Controller
{
    public function __invoke(Request $request, Payment $payment, ReceiptPdf $pdf): Response
    {
        abort_unless(Gate::allows('view-invoices'), 403);

        $bytes = $pdf->render($payment);
        $number = ReceiptView::number($payment);

        activity()
            ->performedOn($payment->account)
            ->event('receipt-downloaded')
            ->withChanges(['old' => [], 'attributes' => ['receipt' => $number, 'invoice' => (string) $payment->invoice?->number]])
            ->log('receipt-downloaded');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('print') ? 'inline' : 'attachment').'; filename="'.$number.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
