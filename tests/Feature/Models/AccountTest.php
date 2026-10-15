<?php

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AddressList;
use App\Models\Ip;
use App\Models\Ipoe;
use App\Models\Recharge;
use Illuminate\Database\UniqueConstraintViolationException;
use Spatie\Activitylog\Models\Activity;

it('reads its status and service type as enums', function () {
    $account = Account::factory()->ipoe()->suspended()->create();

    expect($account->fresh())
        ->status_id->toBe(AccountStatus::Suspended)
        ->service_type->toBe(ServiceType::Ipoe);
});

it('refuses the same static IP on two accounts', function () {
    $ip = Ip::factory()->create();

    Account::factory()->count(2)->create(['ip_id' => $ip->id]);
})->throws(UniqueConstraintViolationException::class);

it('refuses the same IPoE address on two accounts', function () {
    Ipoe::factory()->count(2)->create(['ip_address' => '203.0.113.25']);
})->throws(UniqueConstraintViolationException::class);

it('adds each address list to an account only once', function () {
    $account = Account::factory()->create();
    $list = AddressList::factory()->create();

    $account->addressLists()->attach($list);
    $account->addressLists()->attach($list);
})->throws(UniqueConstraintViolationException::class);

it('refuses a username that is already taken, even by a deleted account', function () {
    Account::factory()->create(['username' => 'alice'])->delete();

    Account::factory()->create(['username' => 'alice']);
})->throws(UniqueConstraintViolationException::class);

it('logs nothing for a new customer saved again without changes', function () {
    $account = Account::factory()->create();

    $account->update(['mac_type' => 1, 'status_id' => 1, 'override_plan' => false]);

    expect(Activity::query()->whereMorphedTo('subject', $account)->pluck('event')->all())->toBe(['created']);
});

it('keeps a Hotspot validity that waits for the first login, without an expiry', function () {
    $account = Account::factory()->hotspotPending(180)->create()->fresh();

    expect($account)
        ->service_type->toBe(ServiceType::Hotspot)
        ->expiry->toBeNull()
        ->hotspot_pending_validity->toBe(180)
        ->hotspot_first_login_at->toBeNull();
});

it('records a recharge that leaves the validity waiting, without a new expiry', function () {
    $recharge = Recharge::factory()->create(['new_expiry' => null, 'new_pending_validity' => 180])->fresh();

    expect($recharge->new_expiry)->toBeNull()->and($recharge->new_pending_validity)->toBe(180);
});
