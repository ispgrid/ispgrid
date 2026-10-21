<?php

namespace App\Billing\Pdf;

use App\Billing\Countries;
use App\Billing\Decimal;
use App\Billing\India\GstStates;
use App\Enums\InvoiceStatus;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceTaxLine;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;

/**
 * Every string the invoice prints, from the invoice's own snapshot: the company, customer, currency and logo as
 * they were at issue, so a later change to settings never changes an old invoice. Labels are English (the PDF is
 * English only); dates use the App settings date format.
 */
final class InvoiceView
{
    /**
     * @return array{
     *     title: string, number: string, date: string, due_date: string, terms: string, place_of_supply: ?string,
     *     bill_period: ?string, validity: ?string, logo: ?string, cancelled: bool, cancel_reason: ?string,
     *     company: array{name: string, lines: list<string>, tax: ?string, extra: list<string>, contact: list<string>},
     *     customer: array{username: string, billing_lines: list<string>, connectivity_lines: list<string>, tax_label: string, tax_number: ?string},
     *     item_code_label: ?string,
     *     lines: list<array{position: int, description: string, sub_description: ?string, item_code: ?string, quantity: string, rate: string, discount: ?string, amount: string}>,
     *     taxable_total: string, tax_lines: list<array{label: string, amount: string}>, round_off: ?string, total: string,
     *     paid: string, balance: string, total_in_words: string, notes: string, bank_details: string, terms_text: string,
     *     signature: bool, signatory: string
     * }
     */
    public static function for(Invoice $invoice): array
    {
        $company = $invoice->company;
        $customer = $invoice->customer;
        $money = self::money($invoice);
        $dateFormat = (string) AppSetting::valueOf('date_format', 'd/m/Y');
        $india = $invoice->tax_setup === 'india-gst';
        $taxLabel = $india ? 'GSTIN' : ((string) AppSetting::valueOf('tax_id_label', '') ?: 'Tax ID');

        return [
            'title' => (string) $invoice->title,
            'number' => $invoice->number,
            'date' => $invoice->issue_date->format($dateFormat),
            'due_date' => $invoice->due_date->format($dateFormat),
            'terms' => $invoice->terms_days === 0 ? 'Due on receipt' : "Net {$invoice->terms_days}",
            'place_of_supply' => $invoice->place_of_supply === null ? null : GstStates::label($invoice->place_of_supply),
            'bill_period' => $invoice->period_from === null || $invoice->period_to === null ? null
                : $invoice->period_from->format($dateFormat).' to '.$invoice->period_to->format($dateFormat),
            'validity' => $invoice->validity_text,
            'logo' => self::logo(self::text($company, 'logo')),
            'cancelled' => $invoice->status === InvoiceStatus::Cancelled,
            'cancel_reason' => $invoice->cancel_reason,
            'company' => [
                'name' => self::text($company, 'name'),
                'lines' => self::companyLines($company),
                'tax' => self::text($company, 'tax_number') === '' ? null : $taxLabel.': '.self::text($company, 'tax_number'),
                'extra' => array_values(array_map(
                    fn (mixed $line): string => is_array($line) ? trim(self::text($line, 'label').': '.self::text($line, 'value'), ': ') : '',
                    is_array($company['extra_lines'] ?? null) ? $company['extra_lines'] : [],
                )),
                'contact' => array_values(array_filter([self::text($company, 'phone'), self::text($company, 'email'), self::text($company, 'website')])),
            ],
            'customer' => [
                'username' => self::text($customer, 'username'),
                'billing_lines' => self::strings($customer['billing_lines'] ?? []),
                'connectivity_lines' => self::strings($customer['connectivity_lines'] ?? []),
                'tax_label' => $taxLabel,
                'tax_number' => self::text($customer, 'tax_number') ?: null,
            ],
            'item_code_label' => $invoice->item_code_label,
            'lines' => array_values($invoice->lines->map(fn (InvoiceLine $line): array => [
                'position' => (int) $line->position,
                'description' => (string) $line->description,
                'sub_description' => $line->sub_description,
                'item_code' => $line->item_code,
                'quantity' => Decimal::trim((string) $line->quantity),
                'rate' => $money->format((string) $line->unit_price),
                'discount' => Decimal::compare((string) $line->discount_amount, '0') > 0
                    ? $money->format((string) $line->discount_amount).' ('.Decimal::trim((string) $line->discount_percent).'%)' : null,
                'amount' => $money->format((string) $line->taxable),
            ])->all()),
            'taxable_total' => $money->format((string) $invoice->taxable_total),
            'tax_lines' => array_values($invoice->taxLines->map(fn (InvoiceTaxLine $line): array => [
                'label' => $line->name.' '.Decimal::trim((string) $line->rate).'%',
                'amount' => $money->format((string) $line->amount),
            ])->all()),
            'round_off' => Decimal::compare((string) $invoice->round_off, '0') === 0 ? null : $money->format((string) $invoice->round_off),
            'total' => $money->format((string) $invoice->total),
            'paid' => $money->format((string) $invoice->paid_total),
            'balance' => $money->format((string) $invoice->balance),
            'total_in_words' => (string) $invoice->total_in_words,
            'notes' => self::text($company, 'notes'),
            'bank_details' => self::text($company, 'bank_details'),
            'terms_text' => self::text($company, 'terms'),
            'signature' => (bool) ($company['show_signature'] ?? true),
            'signatory' => self::text($company, 'name'),
        ];
    }

    /**
     * Amounts in the currency the invoice was issued in (symbol, decimals and grouping from its snapshot).
     */
    public static function money(Invoice $invoice): Money
    {
        $currency = self::currency($invoice);

        return new Money(self::text($currency, 'symbol'), (int) ($currency['decimals'] ?? 2), self::text($currency, 'grouping') ?: 'international');
    }

    /**
     * The invoice's currency snapshot: code, symbol, decimals, grouping.
     *
     * @return array<mixed>
     */
    public static function currency(Invoice $invoice): array
    {
        $company = $invoice->company;

        return is_array($company['currency'] ?? null) ? $company['currency'] : [];
    }

    /**
     * The logo the invoice was issued with, as a data URI; none when there was none or the file is gone.
     */
    private static function logo(string $path): ?string
    {
        if ($path === '' || ! Storage::disk('billing')->exists($path)) {
            return null;
        }

        $bytes = (string) Storage::disk('billing')->get($path);
        $type = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($type, ['image/png', 'image/jpeg'], true) ? 'data:'.$type.';base64,'.base64_encode($bytes) : null;
    }

    /**
     * @param  array<mixed>  $company
     * @return list<string>
     */
    private static function companyLines(array $company): array
    {
        $country = self::text($company, 'country');

        return array_values(array_filter([
            ...self::strings($company['address_lines'] ?? []),
            trim(self::text($company, 'city').' '.self::text($company, 'postal_code')),
            self::text($company, 'state'),
            $country === '' ? '' : Countries::name($country),
        ], fn (string $line): bool => $line !== ''));
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $values)) : [];
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function text(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }
}
