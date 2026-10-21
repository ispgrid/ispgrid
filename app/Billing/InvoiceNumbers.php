<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\InvoiceCounter;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Invoice numbers: one counter per series ('all', or one per year when numbering restarts yearly), formatted with
 * {FY}, {YYYY} and {N}. A back-dated invoice takes the next number of its own year's series. next() locks the
 * counter row, so it must run inside the transaction that saves the invoice: a rollback then gives the number
 * back and two invoices at the same moment get consecutive numbers.
 */
final class InvoiceNumbers
{
    public function __construct(private readonly BillingSettings $settings) {}

    /**
     * 'all' when numbers never restart, otherwise the year of the date: '2026-27' (April start), '2026' (January).
     */
    public function seriesKey(CarbonInterface $date): string
    {
        if (! $this->settings->numbering->restartYearly) {
            return 'all';
        }

        $start = $this->startYear($date);

        return $this->settings->numbering->yearStartMonth === 1 ? (string) $start : $start.'-'.substr((string) ($start + 1), -2);
    }

    public function format(CarbonInterface $date, int $number): string
    {
        $start = $this->startYear($date);
        $year = $this->settings->numbering->yearStartMonth === 1
            ? (string) $start
            : substr((string) $start, -2).'-'.substr((string) ($start + 1), -2);

        return strtr($this->settings->numbering->format, [
            '{FY}' => $year,
            '{YYYY}' => (string) $date->year,
            '{N}' => str_pad((string) $number, $this->settings->numbering->digits, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * Takes the next number of the issue date's series.
     *
     * @return array{number: string, series: string, sequence: int}
     */
    public function next(CarbonInterface $issueDate): array
    {
        $series = $this->seriesKey($issueDate);
        InvoiceCounter::query()->firstOrCreate(['series' => $series], ['next_number' => 1]);
        $counter = InvoiceCounter::query()->where('series', $series)->lockForUpdate()->firstOrFail();

        $sequence = $this->firstFree($issueDate, max($counter->next_number, $this->highestUsed($series) + 1));
        $counter->update(['next_number' => $sequence + 1]);

        return ['number' => $this->format($issueDate, $sequence), 'series' => $series, 'sequence' => $sequence];
    }

    /**
     * The number the next invoice issued on this date would get, without taking it.
     */
    public function preview(CarbonInterface $date): string
    {
        $series = $this->seriesKey($date);
        $counter = InvoiceCounter::query()->where('series', $series)->value('next_number');

        return $this->format($date, $this->firstFree($date, max((int) ($counter ?? 1), $this->highestUsed($series) + 1)));
    }

    /**
     * Sets the next number of today's series (to carry on from another system). It may skip ahead, never back to
     * a number already used.
     */
    public function setNext(int $number): void
    {
        $series = $this->seriesKey(now());

        if ($number < 1 || $number <= $this->highestUsed($series)) {
            throw new InvalidArgumentException(__('The next invoice number must be higher than :number, the last one used.', ['number' => $this->highestUsed($series)]));
        }

        InvoiceCounter::query()->updateOrCreate(['series' => $series], ['next_number' => $number]);
    }

    /**
     * The first number from here whose printed form no invoice has yet. Another series can print the same text
     * (numbering switched to restart yearly, or a format without {FY}), and invoice numbers are unique.
     */
    private function firstFree(CarbonInterface $date, int $sequence): int
    {
        while (Invoice::query()->where('number', $this->format($date, $sequence))->exists()) {
            $sequence++;
        }

        return $sequence;
    }

    private function highestUsed(string $series): int
    {
        return (int) Invoice::query()->where('series', $series)->max('sequence');
    }

    private function startYear(CarbonInterface $date): int
    {
        return $date->month >= $this->settings->numbering->yearStartMonth ? $date->year : $date->year - 1;
    }
}
