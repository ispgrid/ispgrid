<?php

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AddressList;
use App\Models\Ip;
use App\Models\Ipoe;
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

    expect(Activity::query()->where('subject_id', $account->id)->pluck('event')->all())->toBe(['created']);
});
