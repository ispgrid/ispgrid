<?php

namespace App\Support;

use App\Models\UsageDay;
use App\Models\UsagePeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Usage as FreeRADIUS checks limits against it (the usage database): per customer since the last recharge and per
 * day, and for the whole network. Days are the server's calendar days.
 */
final class Usage
{
    /**
     * The customer's usage since their last recharge.
     *
     * @return array{bytes: int, seconds: int}
     */
    public static function sinceRecharge(string $username): array
    {
        $period = UsagePeriod::query()->find($username);

        return ['bytes' => $period->bytes ?? 0, 'seconds' => $period->seconds ?? 0];
    }

    /**
     * The customer's usage from $from (a day) on.
     *
     * @return array{bytes: int, seconds: int}
     */
    public static function since(string $username, CarbonInterface $from): array
    {
        $row = UsageDay::query()->where('username', $username)->where('day', '>=', $from->toDateString())
            ->selectRaw('COALESCE(SUM(bytes_in + bytes_out), 0) AS bytes, COALESCE(SUM(seconds), 0) AS seconds')
            ->toBase()->first();

        return ['bytes' => (int) ($row->bytes ?? 0), 'seconds' => (int) ($row->seconds ?? 0)];
    }

    /**
     * The customer's days with usage in the last $days days, newest first.
     *
     * @return list<array{day: CarbonInterface, download: int, upload: int, seconds: int}>
     */
    public static function days(string $username, int $days): array
    {
        return array_values(UsageDay::query()->where('username', $username)->where('day', '>', now()->subDays($days)->toDateString())
            ->orderByDesc('day')->get()
            ->map(fn (UsageDay $day): array => ['day' => $day->day, 'download' => $day->bytes_out, 'upload' => $day->bytes_in, 'seconds' => $day->seconds])
            ->all());
    }

    /**
     * Data used from $from on, by username, for these customers.
     *
     * @param  list<string>  $usernames
     * @return array<string, int>
     */
    public static function bytesSince(array $usernames, CarbonInterface $from): array
    {
        if ($usernames === []) {
            return [];
        }

        return UsageDay::query()->whereIn('username', $usernames)->where('day', '>=', $from->toDateString())
            ->groupBy('username')->selectRaw('username, SUM(bytes_in + bytes_out) AS bytes')->toBase()->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->username => (int) $row->bytes])->all();
    }

    /**
     * The whole network per day, the last $days days (today included), newest first; a day without usage shows 0.
     *
     * @return list<array{day: CarbonInterface, download: int, upload: int}>
     */
    public static function network(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = DB::table((new UsageDay)->getTable())->where('day', '>=', $from->toDateString())->groupBy('day')
            ->selectRaw('day, SUM(bytes_out) AS download, SUM(bytes_in) AS upload')->get()->keyBy(fn (object $row): string => (string) $row->day);

        $result = [];

        for ($day = now()->startOfDay(); $day->greaterThanOrEqualTo($from); $day = $day->subDay()) {
            $row = $rows[$day->toDateString()] ?? null;
            $result[] = ['day' => $day, 'download' => (int) ($row->download ?? 0), 'upload' => (int) ($row->upload ?? 0)];
        }

        return $result;
    }

    /**
     * The customers who used the most data from $from on.
     *
     * @return list<array{username: string, bytes: int}>
     */
    public static function top(CarbonInterface $from, int $limit): array
    {
        return array_values(DB::table((new UsageDay)->getTable())->where('day', '>=', $from->toDateString())->groupBy('username')
            ->selectRaw('username, SUM(bytes_in + bytes_out) AS bytes')->orderByDesc('bytes')->orderBy('username')->limit($limit)->get()
            ->map(fn (object $row): array => ['username' => (string) $row->username, 'bytes' => (int) $row->bytes])->all());
    }
}
