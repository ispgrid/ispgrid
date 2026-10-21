<?php

namespace App\Billing\Pdf;

use App\Models\Invoice;

/**
 * Turns an invoice into PDF bytes. Core uses dompdf; another renderer (one for every script, say) can replace it
 * by binding this interface.
 */
interface InvoicePdf
{
    public function render(Invoice $invoice): string;
}
