<?php

namespace App\Http\Controllers;

use App\Billing\Pdf\InvoicePdf;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The PDF of an invoice, built now (never stored), for staff with View invoices. ?print=1 opens it in the browser
 * to print; otherwise it downloads. Each one is logged on the customer.
 */
class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, InvoicePdf $pdf): Response
    {
        abort_unless(Gate::allows('view-invoices'), 403);

        $bytes = $pdf->render($invoice);
        $name = str_replace(['/', '\\'], '-', $invoice->number).'.pdf';

        activity()
            ->performedOn($invoice->account)
            ->event('invoice-downloaded')
            ->withChanges(['old' => [], 'attributes' => ['invoice' => $invoice->number]])
            ->log('invoice-downloaded');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('print') ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
