<?php

namespace App\Billing\Pdf;

use App\Billing\AmountInWords;
use App\Billing\Decimal;
use App\Models\AppSetting;
use App\Models\Payment;

/**
 * Every string a payment receipt prints. The company, customer, logo and currency come from the invoice's snapshot,
 * so the receipt matches the invoice it pays. Labels are English (the PDF is English only).
 */
final class ReceiptView
{
    /**
     * @return array{
     *     title: string, number: string, date: string, method: string, reference: ?string, amount: string,
     *     amount_in_words: string, invoice_number: string, invoice_date: string, invoice_total: string,
     *     balance_after: ?string, removed: bool, remove_reason: ?string, logo: ?string,
     *     company: array{name: string, lines: list<string>, tax: ?string, extra: list<string>, contact: list<string>},
     *     customer: array{username: string, billing_lines: list<string>, connectivity_lines: list<string>, tax_label: string, tax_number: ?string},
     *     signature: bool, signatory: string
     * }
     */
    public static function for(Payment $payment): array
    {
        $invoice = $payment->invoice()->with(['lines', 'taxLines'])->firstOrFail();
        $printed = InvoiceView::for($invoice);
        $money = InvoiceView::money($invoice);
        $currency = InvoiceView::currency($invoice);
        $removed = $payment->trashed();

        return [
            'title' => 'PAYMENT RECEIPT',
            'number' => self::number($payment),
            'date' => $payment->paid_at->format(AppSetting::valueOf('date_format', 'd/m/Y').' H:i'),
            'method' => (string) $payment->method?->name,
            'reference' => $payment->reference === null || $payment->reference === '' ? null : $payment->reference,
            'amount' => $money->format((string) $payment->amount),
            'amount_in_words' => AmountInWords::format((string) $payment->amount, is_scalar($currency['code'] ?? null) ? (string) $currency['code'] : '',
                ($currency['grouping'] ?? null) === 'indian'),
            'invoice_number' => $printed['number'],
            'invoice_date' => $printed['date'],
            'invoice_total' => $printed['total'],
            'balance_after' => $removed ? null : $money->format(self::balanceAfter($payment, (string) $invoice->total)),
            'removed' => $removed,
            'remove_reason' => $payment->remove_reason,
            'logo' => $printed['logo'],
            'company' => $printed['company'],
            'customer' => $printed['customer'],
            'signature' => $printed['signature'],
            'signatory' => $printed['signatory'],
        ];
    }

    /**
     * The receipt number, which is also the PDF's file name.
     */
    public static function number(Payment $payment): string
    {
        return 'RCPT-'.$payment->id;
    }

    /**
     * What the invoice still owed once this payment came in: its total less this payment and the ones before it
     * (by time received), leaving out removed payments.
     */
    private static function balanceAfter(Payment $payment, string $total): string
    {
        $paid = Payment::query()->where('invoice_id', $payment->invoice_id)
            ->where(fn ($query) => $query->where('paid_at', '<', $payment->paid_at)
                ->orWhere(fn ($same) => $same->where('paid_at', $payment->paid_at)->where('id', '<=', $payment->id)))
            ->pluck('amount')
            ->reduce(fn (string $sum, mixed $amount): string => Decimal::add($sum, (string) $amount), '0');

        return Decimal::sub($total, $paid);
    }
}
