<?php

use App\Enums\AdvanceRenewalStatus;
use App\Enums\RechargeKind;
use App\Models\Account;
use App\Models\AdvanceRenewal;
use App\Models\Recharge;
use Illuminate\Support\Facades\DB;

it('finds the waiting renewal of a customer', function () {
    $account = Account::factory()->create();
    AdvanceRenewal::factory()->for($account)->create(['status' => AdvanceRenewalStatus::Cancelled]);
    $waiting = AdvanceRenewal::factory()->for($account)->create(['status' => AdvanceRenewalStatus::Waiting]);

    expect($account->fresh()->waitingRenewal->id)->toBe($waiting->id)
        ->and($waiting->fresh()->status)->toBe(AdvanceRenewalStatus::Waiting);
});

it('casts the kind of a recharge, and reads an older row as a recharge', function () {
    $auto = Recharge::factory()->create(['kind' => 'auto']);
    $older = Recharge::factory()->create();
    DB::table('recharges')->where('id', $older->id)->update(['kind' => DB::raw('DEFAULT')]);

    expect($auto->fresh()->kind)->toBe(RechargeKind::Auto)
        ->and($older->fresh()->kind)->toBe(RechargeKind::Recharge);
});

it('starts a customer with auto renew off and no override speed end', function () {
    $account = Account::factory()->create();

    expect($account->fresh())
        ->auto_renew->toBeFalse()
        ->override_until->toBeNull();
});
