<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonInterface;

/**
 * A customer's account statement: invoices (not cancelled) as debits and payments (not removed) as credits, by
 * date, with the running balance owed. Dues are the balance of their open invoices.
 */
final class Statement
{
    /**
     * @return list<array{date: CarbonInterface, text: string, debit: ?string, credit: ?string, balance: string}>
     */
    public static function for(Account $account): array
    {
        $entries = [];

        $invoices = Invoice::query()->where('account_id', $account->id)->where('status', '!=', InvoiceStatus::Cancelled)->get();

        foreach ($invoices as $invoice) {
            $entries[] = ['date' => $invoice->issue_date->startOfDay(), 'order' => [0, $invoice->id], 'text' => __('Invoice :number', ['number' => $invoice->number]), 'debit' => (string) $invoice->total, 'credit' => null];
        }

        $payments = Payment::query()->where('account_id', $account->id)->with(['method', 'invoice'])
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Cancelled))->get();

        foreach ($payments as $payment) {
            $entries[] = [
                'date' => $payment->paid_at, 'order' => [1, $payment->id],
                'text' => __('Payment for :number, :method', ['number' => $payment->invoice?->number, 'method' => $payment->method?->name]),
                'debit' => null, 'credit' => (string) $payment->amount,
            ];
        }

        usort($entries, fn (array $a, array $b): int => [$a['date']->getTimestamp(), ...$a['order']] <=> [$b['date']->getTimestamp(), ...$b['order']]);

        $balance = '0';
        $rows = [];

        foreach ($entries as $entry) {
            $balance = $entry['debit'] !== null ? Decimal::add($balance, $entry['debit']) : Decimal::sub($balance, (string) $entry['credit']);
            $rows[] = ['date' => $entry['date'], 'text' => $entry['text'], 'debit' => $entry['debit'], 'credit' => $entry['credit'], 'balance' => Decimal::round($balance, 2)];
        }

        return $rows;
    }

    /**
     * What the customer owes: the balance of their invoices that are not cancelled.
     */
    public static function dues(Account $account): string
    {
        return Decimal::round((string) Invoice::query()->where('account_id', $account->id)->where('status', '!=', InvoiceStatus::Cancelled)->sum('balance'), 2);
    }
}
