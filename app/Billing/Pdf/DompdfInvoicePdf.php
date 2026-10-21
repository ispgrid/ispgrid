<?php

namespace App\Billing\Pdf;

use App\Models\Invoice;

/**
 * The invoice PDF with dompdf (see DompdfDocument). Built on request only, never at issue.
 */
final class DompdfInvoicePdf implements InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        return DompdfDocument::render(view('pdf.invoice', ['invoice' => InvoiceView::for($invoice->loadMissing(['lines', 'taxLines']))])->render());
    }
}
