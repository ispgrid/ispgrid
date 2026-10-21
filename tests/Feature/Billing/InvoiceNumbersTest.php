<?php

use App\Billing\InvoiceNumbers;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\InvoiceCounter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function numbering(string $format, int $digits = 0, bool $restart = false, int $start = 1): void
{
    AppSetting::put('billing.numbering_format', $format);
    AppSetting::put('billing.number_digits', (string) $digits);
    AppSetting::put('billing.restart_yearly', $restart ? '1' : '0');
    AppSetting::put('billing.year_start_month', (string) $start);
}

/**
 * @return array{number: string, series: string, sequence: int}
 */
function nextNumber(string $date): array
{
    return DB::transaction(fn (): array => app(InvoiceNumbers::class)->next(CarbonImmutable::parse($date)));
}

it('formats like the reference', function () {
    numbering('LINK{FY}-{N}', restart: true, start: 4);
    InvoiceCounter::create(['series' => '2026-27', 'next_number' => 189]);

    expect(nextNumber('2026-10-01'))->toBe(['number' => 'LINK26-27-189', 'series' => '2026-27', 'sequence' => 189])
        ->and(nextNumber('2026-10-01')['number'])->toBe('LINK26-27-190');
});

it('restarts in the month the year starts', function () {
    numbering('LINK{FY}-{N}', restart: true, start: 4);
    InvoiceCounter::create(['series' => '2026-27', 'next_number' => 500]);

    expect(nextNumber('2027-03-31')['number'])->toBe('LINK26-27-500')
        ->and(nextNumber('2027-04-01'))->toBe(['number' => 'LINK27-28-1', 'series' => '2027-28', 'sequence' => 1]);
});

it('pads the number', function () {
    numbering('INV-{N}', digits: 4);

    expect(nextNumber('2026-10-20')['number'])->toBe('INV-0001');
});

it('uses the calendar year when the year starts in January', function () {
    numbering('{FY}/{N}', restart: true, start: 1);

    expect(nextNumber('2026-10-01'))->toBe(['number' => '2026/1', 'series' => '2026', 'sequence' => 1]);
});

it('keeps one series when numbers never restart', function () {
    numbering('{YYYY}-{N}');

    expect(nextNumber('2026-12-31')['number'])->toBe('2026-1')
        ->and(nextNumber('2027-01-01'))->toBe(['number' => '2027-2', 'series' => 'all', 'sequence' => 2]);
});

it('numbers stay consecutive when a transaction rolls back', function () {
    numbering('INV-{N}');

    try {
        DB::transaction(function () {
            app(InvoiceNumbers::class)->next(now());

            throw new RuntimeException('the recharge failed');
        });
    } catch (RuntimeException) {
    }

    expect(nextNumber('2026-10-20')['number'])->toBe('INV-1');
});

it('gives a back-dated invoice the number of its own year', function () {
    numbering('INV/{FY}/{N}', restart: true, start: 4);
    InvoiceCounter::create(['series' => '2025-26', 'next_number' => 40]);
    InvoiceCounter::create(['series' => '2026-27', 'next_number' => 5]);

    expect(nextNumber('2026-01-15')['number'])->toBe('INV/25-26/40')
        ->and(nextNumber('2026-10-20')['number'])->toBe('INV/26-27/5');
});

it('shows the next number without using it', function () {
    numbering('LINK{FY}-{N}', restart: true, start: 4);
    $this->travelTo(CarbonImmutable::parse('2026-10-20'));

    expect(app(InvoiceNumbers::class)->preview(now()))->toBe('LINK26-27-1')
        ->and(app(InvoiceNumbers::class)->preview(now()))->toBe('LINK26-27-1');
});

it('moves the next number forward', function () {
    numbering('INV-{N}');
    app(InvoiceNumbers::class)->setNext(190);

    expect(nextNumber('2026-10-20')['number'])->toBe('INV-190');
});

it('refuses a next number already used', function () {
    numbering('INV-{N}');
    Invoice::factory()->create(['number' => 'INV-7', 'series' => 'all', 'sequence' => 7]);

    app(InvoiceNumbers::class)->setNext(5);
})->throws(InvalidArgumentException::class);

it('never reuses a number when the counter is behind the invoices', function () {
    numbering('INV-{N}');
    Invoice::factory()->create(['number' => 'INV-7', 'series' => 'all', 'sequence' => 7]);

    expect(nextNumber('2026-10-20')['number'])->toBe('INV-8');
});

it('skips a number already printed in another series', function () {
    numbering('INV/{FY}/{N}');
    Invoice::factory()->create(['number' => 'INV/26-27/1', 'series' => 'all', 'sequence' => 1]);
    numbering('INV/{FY}/{N}', restart: true, start: 4);

    expect(nextNumber('2026-10-20'))->toBe(['number' => 'INV/26-27/2', 'series' => '2026-27', 'sequence' => 2]);
});
