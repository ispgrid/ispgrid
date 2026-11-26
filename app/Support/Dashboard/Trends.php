<?php

namespace App\Support\Dashboard;

use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Short series for the dashboard's trend lines, from what the panel already stores. Each series is one grouped
 * query; days or months with nothing in them count as 0. The column and the aggregate are SQL written by the caller,
 * never user input.
 */
final class Trends
{
    /**
     * One value per day from $from to today, oldest first.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @return list<float>
     */
    public static function perDay(EloquentBuilder|QueryBuilder $query, string $column, CarbonInterface $from, string $aggregate = 'COUNT(*)'): array
    {
        $from = $from->toImmutable()->startOfDay();
        $rows = self::grouped($query, "DATE({$column})", $column, $from, now()->toImmutable()->addDay()->startOfDay(), $aggregate);

        $values = [];
        foreach (CarbonPeriod::create($from, '1 day', now()->toImmutable()->startOfDay()) as $day) {
            $values[] = (float) ($rows[$day->format('Y-m-d')] ?? 0);
        }

        return $values;
    }

    /**
     * One value per month for the $months full months that end with last month, oldest first.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @return list<float>
     */
    public static function perMonth(EloquentBuilder|QueryBuilder $query, string $column, int $months, string $aggregate = 'COUNT(*)'): array
    {
        $until = now()->toImmutable()->startOfMonth();
        $from = $until->subMonthsNoOverflow($months);
        $rows = self::grouped($query, "DATE_FORMAT({$column}, '%Y-%m')", $column, $from, $until, $aggregate);

        $values = [];
        foreach (CarbonPeriod::create($from, '1 month', $until->subMonthNoOverflow()) as $month) {
            $values[] = (float) ($rows[$month->format('Y-m')] ?? 0);
        }

        return $values;
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function running(array $values): array
    {
        $total = 0.0;

        return array_map(function (float $value) use (&$total): float {
            return $total += $value;
        }, $values);
    }

    /**
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @return array<string, string|int|float|null>
     */
    private static function grouped(EloquentBuilder|QueryBuilder $query, string $key, string $column, CarbonInterface $from, CarbonInterface $until, string $aggregate): array
    {
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;

        return (clone $base)->where($column, '>=', $from)->where($column, '<', $until)
            ->selectRaw("{$key} AS bucket, {$aggregate} AS total")->groupByRaw($key)
            ->pluck('total', 'bucket')->all();
    }
}
