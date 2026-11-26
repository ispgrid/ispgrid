<?php

use App\Models\Payment;
use App\Support\Dashboard\Trends;
use App\Support\Sparkline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-10 12:00:00')));

it('counts per day and fills empty days with zero', function () {
    usageDay('alice', '2026-10-04', 10);
    usageDay('alice', '2026-10-10', 10);
    usageDay('bob', '2026-10-10', 10);
    usageDay('carol', '2026-10-03', 10);

    $table = DB::table(config('ispgrid.usage_database').'.usage_daily');

    expect(Trends::perDay($table, 'day', now()->subDays(6), 'COUNT(DISTINCT username)'))->toBe([1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 2.0]);
});

it('sums per month for the full months before this one', function () {
    Payment::factory()->create(['amount' => '100.00', 'paid_at' => '2026-09-15 10:00:00']);
    Payment::factory()->create(['amount' => '50.00', 'paid_at' => '2026-09-30 23:00:00']);
    Payment::factory()->create(['amount' => '70.00', 'paid_at' => '2026-04-02 10:00:00']);
    Payment::factory()->create(['amount' => '999.00', 'paid_at' => '2026-10-01 00:00:00']);
    Payment::factory()->create(['amount' => '5.00', 'paid_at' => '2026-03-31 23:59:00']);

    expect(Trends::perMonth(Payment::query(), 'paid_at', 6, 'SUM(amount)'))->toBe([70.0, 0.0, 0.0, 0.0, 0.0, 150.0]);
});

it('keeps a running total', fn () => expect(Trends::running([1.0, 0.0, 2.0]))->toBe([1.0, 1.0, 3.0]));

it('draws sparkline points', function () {
    expect(Sparkline::points([0, 5, 10]))->toBe('0,24 60,12 120,0')
        ->and(Sparkline::points([3, 3]))->toBe('0,12 120,12')
        ->and(Sparkline::points([7]))->toBeNull()
        ->and(Sparkline::points([]))->toBeNull();
});

it('indexes the dates the trends count by', function () {
    expect(Schema::hasIndex('recharges', ['created_at']))->toBeTrue()
        ->and(Schema::hasIndex('accounts', ['created_at']))->toBeTrue();
});
