<?php

use App\Actions\Customers\RechargeAccount;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Radacct;
use App\Models\UsageDay;
use App\Models\UsagePeriod;
use App\Support\UsageBackfill;
use Illuminate\Support\Facades\DB;

function usageToday(string $username): ?UsageDay
{
    return UsageDay::query()->where('username', $username)->whereRaw('day = CURDATE()')->first();
}

it('books what a session reports as it grows, into today and the period', function () {
    $session = Radacct::factory()->create(['username' => 'asha', 'acctinputoctets' => 100, 'acctoutputoctets' => 1000, 'acctsessiontime' => 60]);

    expect(usageToday('asha'))->bytes_in->toBe(100)->bytes_out->toBe(1000)->seconds->toBe(60);

    $session->forceFill(['acctinputoctets' => 150, 'acctoutputoctets' => 4000, 'acctsessiontime' => 360])->save();
    $session->forceFill(['acctinputoctets' => 150, 'acctoutputoctets' => 4000, 'acctsessiontime' => 360])->save();

    expect(usageToday('asha'))->bytes_in->toBe(150)->bytes_out->toBe(4000)->seconds->toBe(360)
        ->and(UsagePeriod::query()->find('asha'))->bytes->toBe(4150)->seconds->toBe(360);
});

it('adds sessions together and keeps customers apart', function () {
    Radacct::factory()->create(['username' => 'asha', 'acctoutputoctets' => 1000]);
    Radacct::factory()->create(['username' => 'asha', 'acctoutputoctets' => 500]);
    Radacct::factory()->create(['username' => 'bina', 'acctoutputoctets' => 7]);

    expect(usageToday('asha')->bytes_out)->toBe(1500)
        ->and(usageToday('bina')->bytes_out)->toBe(7)
        ->and(UsageDay::query()->count())->toBe(2);
});

it('counts a counter the NAS started again from zero', function () {
    $session = Radacct::factory()->create(['username' => 'asha', 'acctoutputoctets' => 5000]);

    $session->forceFill(['acctoutputoctets' => 300])->save();

    expect(usageToday('asha')->bytes_out)->toBe(5300);
});

it('books nothing when only the stop time changes', function () {
    $session = Radacct::factory()->create(['username' => 'asha', 'acctoutputoctets' => 5000]);

    $session->forceFill(['acctstoptime' => now(), 'acctterminatecause' => 'NAS-Reboot'])->save();

    expect(usageToday('asha')->bytes_out)->toBe(5000);
});

it('starts the period again from zero with a recharge', function () {
    $plan = Plan::factory()->create();
    $account = Account::factory()->create(['username' => 'asha', 'plan_id' => $plan->id]);
    $session = Radacct::factory()->create(['username' => 'asha', 'acctoutputoctets' => 5000, 'acctsessiontime' => 600]);

    $this->travel(1)->minutes();
    app(RechargeAccount::class)->handle($account->id, $plan->id, now()->addDays(30), '599', null);

    expect(UsagePeriod::query()->find('asha'))
        ->bytes->toBe(0)->seconds->toBe(0)
        ->since->format('Y-m-d H:i')->toBe(now()->format('Y-m-d H:i'));

    $session->forceFill(['acctoutputoctets' => 5200, 'acctsessiontime' => 660])->save();

    expect(UsagePeriod::query()->find('asha'))->bytes->toBe(200)->seconds->toBe(60)
        ->and(usageToday('asha')->bytes_out)->toBe(5200);
});

it('books the sessions already there once, as FreeRADIUS counted them', function () {
    $account = Account::factory()->create(['username' => 'asha', 'usage_since' => now()->subDays(3)]);
    Account::factory()->create(['username' => 'bina', 'usage_since' => null]);
    Radacct::factory()->create(['username' => 'asha', 'acctstarttime' => now()->subDays(5), 'acctoutputoctets' => 1000, 'acctsessiontime' => 10]);
    Radacct::factory()->create(['username' => 'asha', 'acctstarttime' => now()->subDay(), 'acctinputoctets' => 20, 'acctoutputoctets' => 2000, 'acctsessiontime' => 30]);
    Radacct::factory()->create(['username' => 'bina', 'acctstarttime' => now()->subDays(40), 'acctoutputoctets' => 9]);
    $db = config('ispgrid.usage_database');
    DB::table("{$db}.usage_daily")->delete();
    DB::table("{$db}.usage_period")->delete();

    UsageBackfill::run($db);
    UsageBackfill::run($db);

    $days = UsageDay::query()->where('username', 'asha')->orderBy('day')->get();
    expect($days->map(fn (UsageDay $day): array => [$day->day->toDateString(), $day->bytes_in, $day->bytes_out])->all())->toBe([
        [now()->subDays(5)->toDateString(), 0, 1000],
        [now()->subDay()->toDateString(), 20, 2000],
    ])
        ->and(UsagePeriod::query()->find('asha'))->bytes->toBe(2020)->seconds->toBe(30)
        ->and(UsagePeriod::query()->find('asha')->since->toDateString())->toBe($account->usage_since->toDateString())
        ->and(UsagePeriod::query()->find('bina'))->bytes->toBe(9)->since->toBeNull();
});
