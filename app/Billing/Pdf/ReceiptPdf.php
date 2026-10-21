<?php

namespace App\Billing\Pdf;

use App\Models\Payment;

/**
 * Turns a payment into a receipt PDF. Core uses dompdf; another renderer can replace it by binding this interface.
 */
interface ReceiptPdf
{
    public function render(Payment $payment): string;
}
