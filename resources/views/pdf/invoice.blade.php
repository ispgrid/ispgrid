{{-- The invoice PDF (dompdf: tables only, no flexbox). English only; $invoice comes from App\Billing\Pdf\InvoiceView. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice['number'] }}</title>
    <style>
        @page { margin: 14mm 12mm 18mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2933; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .muted { color: #52606d; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .title { font-size: 15pt; font-weight: bold; letter-spacing: 1px; }
        .company-name { font-size: 12pt; font-weight: bold; }
        .box td { border: 1px solid #cbd2d9; padding: 5px 7px; }
        .label { font-size: 7.5pt; color: #52606d; text-transform: uppercase; }
        .lines th { background: #f0f4f8; border: 1px solid #cbd2d9; padding: 5px; font-size: 8pt; text-align: left; }
        .lines td { border: 1px solid #cbd2d9; padding: 5px; }
        .totals td { padding: 3px 7px; }
        .totals .grand td { border-top: 1px solid #1f2933; font-weight: bold; font-size: 10.5pt; }
        .block { margin-top: 10px; }
        .pre { white-space: pre-line; }
        .cancelled { position: fixed; top: 38%; left: 0; width: 100%; text-align: center; font-size: 60pt; font-weight: bold; color: rgba(200, 30, 30, 0.18); transform: rotate(-25deg); }
    </style>
</head>
<body>
    @if ($invoice['cancelled'])
        <div class="cancelled">CANCELLED</div>
    @endif

    <table>
        <tr>
            <td style="width: 60%;">
                @if ($invoice['logo'])
                    <img src="{{ $invoice['logo'] }}" alt="" style="max-height: 80px; max-width: 220px; margin-bottom: 6px;"><br>
                @endif
                <div class="company-name">{{ $invoice['company']['name'] }}</div>
                @foreach ($invoice['company']['lines'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($invoice['company']['tax'])
                    <div class="bold">{{ $invoice['company']['tax'] }}</div>
                @endif
                @foreach ($invoice['company']['extra'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($invoice['company']['contact'] !== [])
                    <div class="muted">{{ implode('  |  ', $invoice['company']['contact']) }}</div>
                @endif
            </td>
            <td class="right" style="width: 40%;">
                <div class="title">{{ $invoice['title'] }}</div>
                @if ($invoice['cancelled'])
                    <div class="bold" style="color: #b42318;">CANCELLED{{ $invoice['cancel_reason'] ? ': '.$invoice['cancel_reason'] : '' }}</div>
                    @if ($invoice['replaced_by'])
                        <div>Replaced by {{ $invoice['replaced_by'] }}</div>
                    @endif
                @endif
            </td>
        </tr>
    </table>

    <table class="box block">
        <tr>
            <td style="width: 50%;">
                <table>
                    <tr><td class="label" style="border: 0; padding: 1px 0;">Invoice number</td><td class="bold right" style="border: 0; padding: 1px 0;">{{ $invoice['number'] }}</td></tr>
                    <tr><td class="label" style="border: 0; padding: 1px 0;">Invoice date</td><td class="right" style="border: 0; padding: 1px 0;">{{ $invoice['date'] }}</td></tr>
                    @if ($invoice['replaces'])
                        <tr><td class="label" style="border: 0; padding: 1px 0;">Replaces invoice</td><td class="right" style="border: 0; padding: 1px 0;">{{ $invoice['replaces'] }}</td></tr>
                    @endif
                    <tr><td class="label" style="border: 0; padding: 1px 0;">Terms</td><td class="right" style="border: 0; padding: 1px 0;">{{ $invoice['terms'] }}</td></tr>
                    <tr><td class="label" style="border: 0; padding: 1px 0;">Due date</td><td class="right" style="border: 0; padding: 1px 0;">{{ $invoice['due_date'] }}</td></tr>
                </table>
            </td>
            <td style="width: 50%;">
                <table>
                    @if ($invoice['place_of_supply'])
                        <tr><td class="label" style="border: 0; padding: 1px 0;">Place of supply</td><td class="right" style="border: 0; padding: 1px 0;">{{ $invoice['place_of_supply'] }}</td></tr>
                    @endif
                    @if ($invoice['bill_period'])
                        <tr><td class="label" style="border: 0; padding: 1px 0;">Bill period</td><td class="right" style="border: 0; padding: 1px 0;">{{ $invoice['bill_period'] }}</td></tr>
                    @elseif ($invoice['validity'])
                        <tr><td class="label" style="border: 0; padding: 1px 0;">Validity</td><td class="right" style="border: 0; padding: 1px 0;">{{ $invoice['validity'] }}</td></tr>
                    @endif
                    <tr><td class="label" style="border: 0; padding: 1px 0;">Customer ID</td><td class="right" style="border: 0; padding: 1px 0;">{{ $invoice['customer']['username'] }}</td></tr>
                </table>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Bill to</div>
                @foreach ($invoice['customer']['billing_lines'] as $line)
                    <div @class(['bold' => $loop->first])>{{ $line }}</div>
                @endforeach
                @if ($invoice['customer']['tax_number'])
                    <div>{{ $invoice['customer']['tax_label'] }}: {{ $invoice['customer']['tax_number'] }}</div>
                @endif
            </td>
            <td>
                @if ($invoice['customer']['connectivity_lines'] !== [])
                    <div class="label">Connectivity address</div>
                    @foreach ($invoice['customer']['connectivity_lines'] as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                @endif
            </td>
        </tr>
    </table>

    <table class="lines block">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th>Item and description</th>
                @if ($invoice['item_code_label'])
                    <th style="width: 11%;">{{ $invoice['item_code_label'] }}</th>
                @endif
                <th class="right" style="width: 8%;">Qty</th>
                <th class="right" style="width: 13%;">Rate</th>
                <th class="right" style="width: 14%;">Discount</th>
                <th class="right" style="width: 14%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice['lines'] as $line)
                <tr>
                    <td class="center">{{ $line['position'] }}</td>
                    <td>
                        <div class="bold">{{ $line['description'] }}</div>
                        @if ($line['sub_description'])
                            <div class="muted">{{ $line['sub_description'] }}</div>
                        @endif
                    </td>
                    @if ($invoice['item_code_label'])
                        <td>{{ $line['item_code'] }}</td>
                    @endif
                    <td class="right">{{ $line['quantity'] }}</td>
                    <td class="right">{{ $line['rate'] }}</td>
                    <td class="right">{{ $line['discount'] ?? '' }}</td>
                    <td class="right">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="block">
        <tr>
            <td style="width: 55%; padding-right: 12px;">
                <div class="label">Total in words</div>
                <div class="bold">{{ $invoice['total_in_words'] }}</div>
                @if ($invoice['notes'] !== '')
                    <div class="block"><div class="label">Notes</div><div class="pre">{{ $invoice['notes'] }}</div></div>
                @endif
                @if ($invoice['bank_details'] !== '')
                    <div class="block"><div class="label">Bank details</div><div class="pre">{{ $invoice['bank_details'] }}</div></div>
                @endif
            </td>
            <td style="width: 45%;">
                <table class="totals">
                    <tr><td>Sub total</td><td class="right">{{ $invoice['taxable_total'] }}</td></tr>
                    @foreach ($invoice['tax_lines'] as $tax)
                        <tr><td>{{ $tax['label'] }}</td><td class="right">{{ $tax['amount'] }}</td></tr>
                    @endforeach
                    @if ($invoice['round_off'])
                        <tr><td>Round off</td><td class="right">{{ $invoice['round_off'] }}</td></tr>
                    @endif
                    <tr class="grand"><td>Total</td><td class="right">{{ $invoice['total'] }}</td></tr>
                    <tr><td>Paid</td><td class="right">{{ $invoice['paid'] }}</td></tr>
                    <tr><td class="bold">Balance due</td><td class="right bold">{{ $invoice['balance'] }}</td></tr>
                </table>
                @if ($invoice['signature'])
                    <div class="right" style="margin-top: 40px;">
                        <div>For {{ $invoice['signatory'] }}</div>
                        <div style="margin-top: 36px; border-top: 1px solid #9aa5b1; display: inline-block; padding-top: 3px;">Authorised signatory</div>
                    </div>
                @endif
            </td>
        </tr>
    </table>

    @if ($invoice['terms_text'] !== '')
        <div class="block"><div class="label">Terms and conditions</div><div class="pre muted">{{ $invoice['terms_text'] }}</div></div>
    @endif
</body>
</html>
