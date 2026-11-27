<?php

use App\Actions\Customers\CreateAccount;
use App\Actions\Customers\DeleteAccount;
use App\Actions\Customers\SetAccountStatus;
use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\AccountNumbers;
use App\Support\Dashboard\TodayFigures;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * A Hotspot card the way the voucher add-on makes one.
 */
function addonCard(string $code, string $source = 'hotspot-vouchers'): Account
{
    return app(CreateAccount::class)->handle([
        'username' => $code, 'password' => $code, 'fullname' => 'Voucher', 'service_type' => ServiceType::Hotspot,
        'mac_type' => 2, 'billing_free' => true,
    ], $source);
}

it('creates an Inactive customer for an add-on, with its FreeRADIUS rows, account ID and one Created entry', function () {
    AppSetting::put(AccountNumbers::PREFIX, 'V-');
    AppSetting::put(AccountNumbers::START, '100');
    AppSetting::put(AccountNumbers::NEXT, '100');

    $account = addonCard('K7PX9Q');
    $activity = Activity::query()->whereMorphedTo('subject', $account)->sole();

    expect($account->fresh())
        ->status_id->toBe(AccountStatus::Inactive)
        ->source->toBe('hotspot-vouchers')
        ->service_type->toBe(ServiceType::Hotspot)
        ->plan_id->toBeNull()
        ->billing_free->toBeTrue()
        ->account_number->toBe('V-100')
        ->and(DB::table('radcheck')->where(['username' => 'K7PX9Q', 'attribute' => 'Cleartext-Password'])->value('value'))->toBe('K7PX9Q')
        ->and(DB::table('radcheck')->where(['username' => 'K7PX9Q', 'attribute' => 'Acct-Status-Id'])->value('value'))->toBe('2')
        ->and($activity->event)->toBe('created')
        ->and($activity->attribute_changes['attributes'])->toMatchArray(['username' => 'K7PX9Q', 'source' => 'hotspot-vouchers'])
        ->and($activity->attribute_changes['attributes'])->not->toHaveKey('password');
});

it('refuses columns, sources and logins it does not allow', function (array $columns, string $source) {
    app(CreateAccount::class)->handle([
        'username' => 'K7PX9Q', 'password' => 'K7PX9Q', 'fullname' => 'Voucher', 'service_type' => ServiceType::Hotspot, ...$columns,
    ], $source);
})->with([
    'a column outside the list' => [['plan_id' => 1], 'hotspot-vouchers'],
    'a status' => [['status_id' => 1], 'hotspot-vouchers'],
    'an uppercase source' => [[], 'Vouchers'],
    'an empty source' => [[], ''],
    'a password in Hindi' => [['password' => 'पासवर्ड'], 'hotspot-vouchers'],
    'a username with a space' => [['username' => 'K7 PX'], 'hotspot-vouchers'],
])->throws(InvalidArgumentException::class);

it('refuses a username another customer has, deleted or not', function () {
    Account::factory()->trashed()->create(['username' => 'K7PX9Q']);

    addonCard('K7PX9Q');
})->throws(UniqueConstraintViolationException::class);

it('changes the status with a remark, and does nothing when it is already set', function () {
    $account = addonCard('K7PX9Q');
    $setStatus = app(SetAccountStatus::class);

    expect($setStatus->handle($account->id, AccountStatus::Suspended, 'Batch switched off'))->toBeTrue()
        ->and($setStatus->handle($account->id, AccountStatus::Suspended, 'Again'))->toBeFalse()
        ->and($account->fresh()->status_id)->toBe(AccountStatus::Suspended)
        ->and(DB::table('radcheck')->where(['username' => 'K7PX9Q', 'attribute' => 'Acct-Status-Id'])->value('value'))->toBe('3')
        ->and(Activity::query()->where('event', 'status-changed')->sole()->attribute_changes['attributes'])
        ->toEqual(['status' => 'Suspended', 'remark' => 'Batch switched off']);
});

it('deletes a customer so they can no longer log in', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    app(SyncAccountToRadius::class)->handle($account);

    app(DeleteAccount::class)->handle($account->id);

    expect(Account::withTrashed()->findOrFail($account->id)->trashed())->toBeTrue()
        ->and(DB::table('radcheck')->where('username', 'alice')->count())->toBe(0)
        ->and(Activity::query()->where('subject_id', $account->id)->where('event', 'deleted')->count())->toBe(1);
});

it('lists customers an add-on made only when asked', function () {
    Account::factory()->create(['username' => 'alice']);

    Livewire::test('pages::customers.index')->assertSee('alice')->assertDontSee(__('Source'));

    addonCard('K7PX9Q');
    addonCard('LOBBY1', 'lobby-wifi');

    Livewire::test('pages::customers.index')->assertSee('alice')->assertDontSee('K7PX9Q')->assertSee(__('Source'));
    Livewire::test('pages::customers.index')->set('source', 'hotspot-vouchers')->assertSee('K7PX9Q')->assertDontSee('alice')->assertDontSee('LOBBY1');
    Livewire::test('pages::customers.index')->set('source', 'all')->assertSee('K7PX9Q')->assertSee('alice')->assertSee('LOBBY1');
});

it('leaves customers an add-on made out of the dashboard counts', function () {
    Account::factory()->create(['status_id' => AccountStatus::Inactive]);
    addonCard('K7PX9Q');
    addonCard('K7PX9R');

    $cards = collect(TodayFigures::cards(User::factory()->create()))->keyBy('label');

    expect($cards['Total']['value'])->toBe(1)
        ->and($cards['Disabled']['value'])->toBe(1)
        ->and($cards['New today']['value'])->toBe(1)
        ->and(collect($cards['New this month']['trend'])->last())->toBe(1.0);
});
