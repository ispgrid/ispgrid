<?php

namespace App\Http\Controllers;

use App\Billing\BillingSettings;
use App\Billing\Pdf\InvoicePdf;
use App\Billing\Pdf\ReceiptPdf;
use App\Billing\Pdf\ReceiptView;
use App\Enums\InvoiceStatus;
use App\Http\Middleware\SelfcareSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SelfcareAccount;
use Illuminate\Http\Response;

/**
 * The customer's own invoice and receipt PDFs in Selfcare, built now with the staff PDF builders. Only rows of the
 * logged-in customer open (anything else is not found), and only while invoicing is on. Cancelled invoices and
 * removed payments are not offered to customers. Each download is logged on the customer, by the customer.
 */
class SelfcareDocumentController extends Controller
{
    public function invoice(int $invoice, InvoicePdf $pdf): Response
    {
        $account = $this->account();
        $row = Invoice::query()->where('account_id', $account->id)->where('status', '!=', InvoiceStatus::Cancelled)->findOrFail($invoice);

        activity()->performedOn($account)->causedBy($account)->event('invoice-downloaded')
            ->withChanges(['old' => [], 'attributes' => ['invoice' => $row->number]])
            ->log('invoice-downloaded');

        return $this->pdf($pdf->render($row), str_replace(['/', '\\'], '-', $row->number));
    }

    public function receipt(int $payment, ReceiptPdf $pdf): Response
    {
        $account = $this->account();
        $row = Payment::query()->where('account_id', $account->id)->findOrFail($payment);
        $number = ReceiptView::number($row);

        activity()->performedOn($account)->causedBy($account)->event('receipt-downloaded')
            ->withChanges(['old' => [], 'attributes' => ['receipt' => $number, 'invoice' => (string) $row->invoice?->number]])
            ->log('receipt-downloaded');

        return $this->pdf($pdf->render($row), $number);
    }

    private function account(): SelfcareAccount
    {
        abort_unless(BillingSettings::current()->canIssue(), 404);

        return SelfcareSession::account() ?? abort(403);
    }

    private function pdf(string $bytes, string $name): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
