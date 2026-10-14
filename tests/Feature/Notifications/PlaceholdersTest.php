<?php

use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Plan;
use App\Models\Recharge;
use App\Notifications\Placeholders;
use Carbon\CarbonImmutable;
use Database\Seeders\AppSettingSeeder;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    AppSetting::put('notice_isp_name', 'Fast Net');
    AppSetting::put('notice_phone', '1800 123');
});

it('fills every value a rule can use', function () {
    $now = CarbonImmutable::parse('2026-08-19 10:00');
    $plan = Plan::factory()->create(['name' => 'Home 25', 'download' => '25M', 'price' => 2000]);
    $account = Account::factory()->create([
        'username' => 'asha01', 'fullname' => 'Asha Rao', 'mobile' => '9876543210', 'plan_id' => $plan->id,
        'expiry' => '2026-08-22 23:33:00',
    ]);
    $recharge = Recharge::query()->create(['account_id' => $account->id, 'plan_id' => $plan->id, 'new_expiry' => $account->expiry, 'amount' => '2000']);

    $keys = array_keys(Placeholders::labels());
    $result = (new Placeholders)->values($keys, $account, $recharge, $now);

    expect(array_combine($keys, $result['values']))->toBe([
        'customer_name' => 'Asha Rao',
        'username' => 'asha01',
        'mobile' => '9876543210',
        'plan_name' => 'Home 25',
        'plan_speed' => '25M',
        'plan_price' => '2000.00',
        'amount_paid' => '2000.00',
        'recharge_reference' => (string) $recharge->id,
        'expiry_date' => '22-Aug-2026',
        'expiry_datetime' => '22-Aug-2026 11:33PM',
        'days_left' => '3',
        'isp_name' => 'Fast Net',
        'support_number' => '1800 123',
    ])->and($result['trimmed'])->toBeFalse();
});

it('cuts a value to the DLT limit of 30 characters and says so', function () {
    $account = Account::factory()->create(['fullname' => str_repeat('A', 40)]);

    $result = (new Placeholders)->values(['customer_name'], $account, null, CarbonImmutable::now());

    expect($result['values'][0])->toBe(str_repeat('A', 30))
        ->and($result['trimmed'])->toBeTrue();
});

it('says zero days left once the plan has expired, and nothing for a customer who never expires', function () {
    $now = CarbonImmutable::parse('2026-08-19 10:00');
    $expired = Account::factory()->create(['expiry' => '2026-08-17 10:00']);
    $never = Account::factory()->create(['expiry' => null]);

    expect((new Placeholders)->values(['days_left', 'expiry_date'], $expired, null, $now)['values'])->toBe(['0', '17-Aug-2026'])
        ->and((new Placeholders)->values(['days_left', 'expiry_date'], $never, null, $now)['values'])->toBe(['', '']);
});

it('shows the value names when there is no customer to take values from', function () {
    expect((new Placeholders)->values(['customer_name', 'plan_name'], null, null, CarbonImmutable::now())['values'])
        ->toBe(['[Customer name]', '[Plan name]']);
});

it('fills the approved text for the preview', function () {
    expect(Placeholders::preview('Dear {1}, renew by {2}. {1}!', ['Asha', '22-Aug-2026']))->toBe('Dear Asha, renew by 22-Aug-2026. Asha!')
        ->and(Placeholders::preview(null, ['Asha']))->toBeNull();
});
