<?php

use App\Billing\InvoiceCalculator;
use App\Billing\InvoiceDraft;
use App\Billing\LineInput;
use App\Billing\Tax\IndiaGst;
use App\Billing\Tax\NoTax;
use App\Billing\Tax\SingleTax;
use App\Billing\Tax\TaxContext;
use App\Billing\Tax\TaxSetup;

function line(string $price, string $qty = '1', string $discount = '0', bool $taxed = true): LineInput
{
    return new LineInput('Plan', null, null, $qty, $price, $discount, $taxed);
}

/**
 * India GST at 18% with the company in Uttar Pradesh (09), sold to the given state.
 *
 * @return array{0: TaxSetup, 1: array<string, string>, 2: TaxContext}
 */
function gst(?string $placeOfSupply): array
{
    return [new IndiaGst, ['rate' => '18', 'state' => '09'], new TaxContext($placeOfSupply)];
}

/**
 * @return array{0: TaxSetup, 1: array<string, string>, 2: TaxContext}
 */
function single(string $name, string $rate): array
{
    return [new SingleTax, ['name' => $name, 'rate' => $rate], new TaxContext(null)];
}

/**
 * @return array{0: TaxSetup, 1: array<string, string>, 2: TaxContext}
 */
function none(): array
{
    return [new NoTax, [], new TaxContext(null)];
}

/**
 * @param  list<LineInput>  $lines
 * @param  array{0: TaxSetup, 1: array<string, string>, 2: TaxContext}  $tax
 */
function calc(array $lines, array $tax, bool $roundOff = false, bool $include = false): InvoiceDraft
{
    return (new InvoiceCalculator)->calculate($lines, $tax[0], $tax[1], $tax[2], $include, $roundOff, 2);
}

it('adds 18% GST in the home state', function () {
    $draft = calc([line('99.00')], gst('09'));

    expect([$draft->taxLines[0]->label(), $draft->taxLines[0]->amount, $draft->taxLines[1]->label(), $draft->taxLines[1]->amount, $draft->taxableTotal, $draft->total])
        ->toBe(['CGST 9%', '8.91', 'SGST 9%', '8.91', '99.00', '116.82']);
});

it('rounds off when asked', function () {
    $draft = calc([line('99.00')], gst('09'), roundOff: true);

    expect([$draft->total, $draft->roundOff])->toBe(['117.00', '0.18'])
        ->and(calc([line('99.00')], gst('09'))->roundOff)->toBe('0.00');
});

it('charges IGST like the reference invoice', function () {
    $draft = calc([line('800.00', qty: '10')], gst('07'));

    expect([$draft->taxLines[0]->label(), $draft->taxLines[0]->amount, $draft->total])->toBe(['IGST 18%', '1440.00', '9440.00'])
        ->and($draft->taxLines)->toHaveCount(1);
});

it('applies the discount before tax', function () {
    $draft = calc([line('500.00', discount: '50')], gst('09'));

    expect([$draft->lines[0]->amount, $draft->lines[0]->discountAmount, $draft->lines[0]->taxable, $draft->total])
        ->toBe(['500.00', '250.00', '250.00', '295.00']);
});

it('takes tax out of a price that includes it', function () {
    $draft = calc([line('117.00')], gst('09'), include: true);

    expect([$draft->taxableTotal, $draft->taxLines[0]->amount, $draft->taxLines[1]->amount, $draft->total])
        ->toBe(['99.15', '8.93', '8.92', '117.00']);
});

it('makes the lines add up when tax is taken out of several prices', function () {
    $draft = calc([line('117.00'), line('117.00'), line('117.00')], gst('09'), include: true);

    $sum = array_reduce($draft->lines, fn (string $carry, $line): string => bcadd($carry, $line->taxable, 2), '0');

    expect($sum)->toBe($draft->taxableTotal)
        ->and($draft->total)->toBe('351.00');
});

it('does not tax an untaxed line', function () {
    $draft = calc([line('99.00', taxed: false)], gst('09'));

    expect([$draft->taxTotal, $draft->total])->toBe(['0.00', '99.00'])
        ->and($draft->taxLines)->toBe([]);
});

it('taxes only the taxed lines of a mixed invoice', function () {
    $draft = calc([line('1000.00'), line('500.00', taxed: false)], gst('09'));

    expect([$draft->taxableTotal, $draft->taxTotal, $draft->total])->toBe(['1500.00', '180.00', '1680.00']);
});

it('works with one tax and with none', function () {
    expect([calc([line('100.00')], single('VAT', '20'))->total, calc([line('100.00')], none())->total])->toBe(['120.00', '100.00']);
});

it('works with a currency without decimals', function () {
    $draft = (new InvoiceCalculator)->calculate([line('999')], new IndiaGst, ['rate' => '18', 'state' => '09'], new TaxContext('09'), false, false, 0);

    expect([$draft->taxLines[0]->amount, $draft->total])->toBe(['90', '1179']);
});
