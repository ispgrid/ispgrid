<?php

namespace App\Billing;

use App\Billing\Tax\TaxComponent;
use App\Billing\Tax\TaxContext;
use App\Billing\Tax\TaxLine;
use App\Billing\Tax\TaxSetup;

/**
 * Works out an invoice: each line's amount and discount, the taxable value, the tax lines of the tax setup, and
 * the total, with an optional round off to a whole unit. Every amount is rounded half up to the currency's
 * decimals as it is made, so the printed lines always add up.
 */
final class InvoiceCalculator
{
    /**
     * @param  list<LineInput>  $lines
     * @param  array<string, string>  $taxConfig
     */
    public function calculate(array $lines, TaxSetup $setup, array $taxConfig, TaxContext $context, bool $pricesIncludeTax, bool $roundOff, int $decimals): InvoiceDraft
    {
        $components = $setup->components($taxConfig, $context);
        $rate = array_reduce($components, fn (string $sum, TaxComponent $component): string => Decimal::add($sum, $component->rate), '0');
        $round = fn (string $value): string => Decimal::round($value, $decimals);

        $nets = [];
        $worked = [];

        foreach ($lines as $index => $line) {
            $amount = $round(Decimal::mul($line->quantity, $line->unitPrice, 6));
            $discount = $round(Decimal::percentOf($amount, $line->discountPercent));
            $nets[$index] = $round(Decimal::sub($amount, $discount));
            $worked[$index] = [$amount, $discount];
        }

        $taxed = array_keys(array_filter($lines, fn (LineInput $line): bool => $line->taxed));
        $taxables = $nets;
        $taxAmounts = [];

        if ($taxed !== [] && $components !== []) {
            $taxedNet = array_reduce($taxed, fn (string $sum, int $index): string => Decimal::add($sum, $nets[$index]), '0');

            if ($pricesIncludeTax) {
                $taxedTaxable = $round(Decimal::div(Decimal::mul($taxedNet, '100'), Decimal::add('100', $rate), 6));
                $taxables = $this->spread($nets, $taxed, $taxedTaxable, fn (string $net): string => $round(Decimal::div(Decimal::mul($net, '100'), Decimal::add('100', $rate), 6)));
                $taxAmounts = $this->share(Decimal::sub($taxedNet, $taxedTaxable), $components, $rate, $round);
            } else {
                foreach ($components as $component) {
                    $taxAmounts[] = $round(Decimal::percentOf($taxedNet, $component->rate));
                }
            }
        }

        $draftLines = [];

        foreach ($lines as $index => $line) {
            $draftLines[] = new DraftLine($line, $worked[$index][0], $worked[$index][1], $round($taxables[$index]));
        }

        $taxLines = [];

        foreach ($taxAmounts as $position => $amount) {
            $taxLines[] = new TaxLine($components[$position]->name, $components[$position]->rate, $amount);
        }

        $taxableTotal = $round(array_reduce($draftLines, fn (string $sum, DraftLine $line): string => Decimal::add($sum, $line->taxable), '0'));
        $taxTotal = $round(array_reduce($taxLines, fn (string $sum, TaxLine $line): string => Decimal::add($sum, $line->amount), '0'));
        $total = $round(Decimal::add($taxableTotal, $taxTotal));
        $roundOffAmount = $round('0');

        if ($roundOff) {
            $whole = $round(Decimal::round($total, 0));
            $roundOffAmount = $round(Decimal::sub($whole, $total));
            $total = $whole;
        }

        return new InvoiceDraft($draftLines, $taxLines, $taxableTotal, $taxTotal, $roundOffAmount, $total);
    }

    /**
     * Each taxed line's taxable value with tax taken out, the last taxed line taking what rounding left over so
     * the lines add up to the total taxable value.
     *
     * @param  array<int, string>  $nets
     * @param  list<int>  $taxed
     * @param  callable(string): string  $taxableOf
     * @return array<int, string>
     */
    private function spread(array $nets, array $taxed, string $taxedTaxable, callable $taxableOf): array
    {
        $taxables = $nets;
        $left = $taxedTaxable;
        $last = end($taxed);

        foreach ($taxed as $index) {
            $taxables[$index] = $index === $last ? $left : $taxableOf($nets[$index]);
            $left = Decimal::sub($left, $taxables[$index]);
        }

        return $taxables;
    }

    /**
     * The tax total shared between the components by their rates, the last one taking the remainder.
     *
     * @param  list<TaxComponent>  $components
     * @param  callable(string): string  $round
     * @return list<string>
     */
    private function share(string $taxTotal, array $components, string $rate, callable $round): array
    {
        $amounts = [];
        $left = $taxTotal;
        $last = count($components) - 1;

        foreach ($components as $position => $component) {
            $amounts[] = $position === $last || Decimal::compare($rate, '0') === 0
                ? $round($left)
                : $round(Decimal::div(Decimal::mul($taxTotal, $component->rate), $rate, 6));
            $left = Decimal::sub($left, $amounts[$position]);
        }

        return $amounts;
    }
}
