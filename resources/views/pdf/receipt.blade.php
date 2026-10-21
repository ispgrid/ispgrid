{{-- The payment receipt PDF (dompdf: tables only, no flexbox). English only; $receipt comes from App\Billing\Pdf\ReceiptView. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $receipt['number'] }}</title>
    <style>
        @page { margin: 14mm 12mm 18mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2933; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .muted { color: #52606d; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .title { font-size: 15pt; font-weight: bold; letter-spacing: 1px; }
        .company-name { font-size: 12pt; font-weight: bold; }
        .box td { border: 1px solid #cbd2d9; padding: 5px 7px; }
        .label { font-size: 7.5pt; color: #52606d; text-transform: uppercase; }
        .row td { border: 0; padding: 1px 0; }
        .amount { font-size: 16pt; font-weight: bold; }
        .totals td { padding: 3px 7px; }
        .totals .grand td { border-top: 1px solid #1f2933; font-weight: bold; }
        .block { margin-top: 10px; }
        .removed { position: fixed; top: 38%; left: 0; width: 100%; text-align: center; font-size: 60pt; font-weight: bold; color: rgba(200, 30, 30, 0.18); transform: rotate(-25deg); }
    </style>
</head>
<body>
    @if ($receipt['removed'])
        <div class="removed">REMOVED</div>
    @endif

    <table>
        <tr>
            <td style="width: 60%;">
                @if ($receipt['logo'])
                    <img src="{{ $receipt['logo'] }}" alt="" style="max-height: 80px; max-width: 220px; margin-bottom: 6px;"><br>
                @endif
                <div class="company-name">{{ $receipt['company']['name'] }}</div>
                @foreach ($receipt['company']['lines'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($receipt['company']['tax'])
                    <div class="bold">{{ $receipt['company']['tax'] }}</div>
                @endif
                @foreach ($receipt['company']['extra'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($receipt['company']['contact'] !== [])
                    <div class="muted">{{ implode('  |  ', $receipt['company']['contact']) }}</div>
                @endif
            </td>
            <td class="right" style="width: 40%;">
                <div class="title">{{ $receipt['title'] }}</div>
                @if ($receipt['removed'])
                    <div class="bold" style="color: #b42318;">REMOVED{{ $receipt['remove_reason'] ? ': '.$receipt['remove_reason'] : '' }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="box block">
        <tr>
            <td style="width: 50%;">
                <table>
                    <tr class="row"><td class="label">Receipt number</td><td class="bold right">{{ $receipt['number'] }}</td></tr>
                    <tr class="row"><td class="label">Received on</td><td class="right">{{ $receipt['date'] }}</td></tr>
                    <tr class="row"><td class="label">Method</td><td class="right">{{ $receipt['method'] }}</td></tr>
                    @if ($receipt['reference'])
                        <tr class="row"><td class="label">Reference</td><td class="right">{{ $receipt['reference'] }}</td></tr>
                    @endif
                </table>
            </td>
            <td style="width: 50%;">
                <table>
                    <tr class="row"><td class="label">Invoice number</td><td class="right">{{ $receipt['invoice_number'] }}</td></tr>
                    <tr class="row"><td class="label">Invoice date</td><td class="right">{{ $receipt['invoice_date'] }}</td></tr>
                    <tr class="row"><td class="label">Customer ID</td><td class="right">{{ $receipt['customer']['username'] }}</td></tr>
                </table>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Received from</div>
                @foreach ($receipt['customer']['billing_lines'] as $line)
                    <div @class(['bold' => $loop->first])>{{ $line }}</div>
                @endforeach
                @if ($receipt['customer']['tax_number'])
                    <div>{{ $receipt['customer']['tax_label'] }}: {{ $receipt['customer']['tax_number'] }}</div>
                @endif
            </td>
            <td>
                <div class="label">Amount received</div>
                <div class="amount">{{ $receipt['amount'] }}</div>
                <div class="bold">{{ $receipt['amount_in_words'] }}</div>
            </td>
        </tr>
    </table>

    <table class="block">
        <tr>
            <td style="width: 55%;"></td>
            <td style="width: 45%;">
                <table class="totals">
                    <tr><td>Invoice total</td><td class="right">{{ $receipt['invoice_total'] }}</td></tr>
                    <tr><td>This payment</td><td class="right">{{ $receipt['amount'] }}</td></tr>
                    @if ($receipt['balance_after'] !== null)
                        <tr class="grand"><td>Balance due after this payment</td><td class="right">{{ $receipt['balance_after'] }}</td></tr>
                    @endif
                </table>
                @if ($receipt['signature'])
                    <div class="right" style="margin-top: 40px;">
                        <div>For {{ $receipt['signatory'] }}</div>
                        <div style="margin-top: 36px; border-top: 1px solid #9aa5b1; display: inline-block; padding-top: 3px;">Authorised signatory</div>
                    </div>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
