<?php

namespace App\Billing\Pdf;

use App\Models\Payment;

/**
 * The payment receipt PDF with dompdf (see DompdfDocument). Built on request only.
 */
final class DompdfReceiptPdf implements ReceiptPdf
{
    public function render(Payment $payment): string
    {
        return DompdfDocument::render(view('pdf.receipt', ['receipt' => ReceiptView::for($payment)])->render());
    }
}
