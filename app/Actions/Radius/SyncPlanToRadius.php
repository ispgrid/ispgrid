<?php

namespace App\Actions\Radius;

use App\Enums\SlotMode;
use App\Models\Plan;
use App\Support\RadiusText;
use App\Support\RateLimit;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds a plan's radgroupreply rows (groupname = slug) from the plan (app-integration.md sections 1 and 1b).
 * Call it inside the save's transaction: FreeRADIUS reads the old rows until the commit and the new ones after.
 * Not final, so a test can make it fail and prove the save rolls back.
 */
class SyncPlanToRadius
{
    private const GIGABYTE = 1_073_741_824;

    private const HOUR = 3600;

    /**
     * Limit columns, the attribute each one becomes and the factor to bytes or seconds.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const LIMITS = [
        'limit_after' => ['Isp-Data-Limit', self::GIGABYTE],
        'time_limit_hours' => ['Isp-Time-Limit', self::HOUR],
        'daily_data_gb' => ['Isp-Daily-Data', self::GIGABYTE],
        'daily_time_hours' => ['Isp-Daily-Time', self::HOUR],
        'monthly_data_gb' => ['Isp-Monthly-Data', self::GIGABYTE],
        'monthly_time_hours' => ['Isp-Monthly-Time', self::HOUR],
    ];

    /**
     * @return bool whether FreeRADIUS now reads different rows (online customers on the plan then need a disconnect)
     */
    public function handle(Plan $plan): bool
    {
        $plan->load(['pool', 'addressLists', 'radiusAttributes']);
        $rows = array_map(fn (array $row): array => [
            'groupname' => $plan->slug,
            'attribute' => $row[0],
            'op' => $row[1],
            'value' => (string) $row[2],
        ], $this->rows($plan));
        RadiusText::check('radgroupreply', $rows);
        $before = $this->snapshot($plan->slug);

        DB::table('radgroupreply')->where('groupname', $plan->slug)->delete();
        DB::table('radgroupreply')->insert($rows);

        return $this->snapshot($plan->slug) !== $before;
    }

    /**
     * @return list<string>
     */
    private function snapshot(string $slug): array
    {
        return DB::table('radgroupreply')->where('groupname', $slug)->orderBy('id')->get(['attribute', 'op', 'value'])
            ->map(fn (object $row): string => "{$row->attribute} {$row->op} {$row->value}")
            ->all();
    }

    public static function rateLimit(Plan $plan): RateLimit
    {
        return new RateLimit(
            $plan->upload, $plan->download,
            $plan->burst_upload, $plan->burst_download,
            $plan->threshold_upload, $plan->threshold_download,
            $plan->burst_time, $plan->priority,
            $plan->min_upload, $plan->min_download,
        );
    }

    /**
     * @return list<array{0: string, 1: string, 2: string|int}>
     */
    private function rows(Plan $plan): array
    {
        $rows = [['Mikrotik-Rate-Limit', '=', self::rateLimit($plan)->toString()]];

        if ($plan->pool !== null) {
            $rows[] = ['Framed-Pool', '=', $plan->pool->name];
        }

        foreach ($plan->addressLists->sortBy('name') as $list) {
            $rows[] = ['Isp-Plan-Address-List', '+=', $list->name];
        }

        foreach (self::LIMITS as $column => [$attribute, $factor]) {
            if ($plan->{$column} !== null) {
                $rows[] = [$attribute, '=', $plan->{$column} * $factor];
            }
        }

        if ($plan->is_fup) {
            $rows[] = ['Isp-Fup-Rate', '=', (new RateLimit((string) $plan->fup_upload, (string) $plan->fup_download))->toString()];
        }

        if ($plan->slot_mode !== null) {
            $rows[] = ['Isp-Slot-Mode', '=', $plan->slot_mode->value];
            $rows[] = ['Isp-Slot-Days', '=', (string) $plan->slot_days];
            $rows[] = ['Isp-Slot-From', '=', (int) $plan->slot_from];
            $rows[] = ['Isp-Slot-To', '=', (int) $plan->slot_to];

            if ($plan->slot_full_days) {
                $rows[] = ['Isp-Slot-Full-Days', '=', 1];
            }

            if ($plan->slot_mode === SlotMode::Boost) {
                $rows[] = ['Isp-Slot-Rate', '=', (new RateLimit((string) $plan->slot_upload, (string) $plan->slot_download))->toString()];
            }
        }

        foreach ($plan->radiusAttributes->sortBy('id') as $attribute) {
            $rows[] = [$attribute->attribute, $attribute->op, $attribute->value];
        }

        return $rows;
    }
}
