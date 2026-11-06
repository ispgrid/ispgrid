<?php

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\DisconnectReason;
use App\Enums\Permission;
use App\Jobs\DisconnectAccount;
use App\Models\Account;
use App\Models\Radacct;
use App\Models\UsageDay;
use App\Models\UsagePeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create(['username' => 'link_priyankesh']);
    app(SyncAccountToRadius::class)->handle($this->account);
});

function renameTo(Account $account, string $username): Testable
{
    return Livewire::test('pages::customers.show', ['account' => $account->id])->set('new_username', $username)->call('rename');
}

it('moves what FreeRADIUS reads to the new username and keeps the session history', function () {
    DB::table('account_macs')->insert(['username' => 'link_priyankesh', 'mac_norm' => 'AABBCC000001']);
    DB::table('reject_counters')->insert(['username' => 'link_priyankesh', 'mac' => 'AABBCC000001', 'counter' => 9, 'last_update' => now()]);
    UsagePeriod::restart('link_priyankesh', now());
    DB::table((new UsageDay)->getTable())->insert(['username' => 'link_priyankesh', 'day' => now()->toDateString(), 'bytes_in' => 5, 'bytes_out' => 7, 'seconds' => 60]);
    $session = Radacct::factory()->closed()->create(['username' => 'link_priyankesh']);
    $checks = DB::table('radcheck')->where('username', 'link_priyankesh')->count();

    renameTo($this->account, 'link_priyanka')->assertHasNoErrors();

    expect($this->account->fresh()->username)->toBe('link_priyanka')
        ->and(DB::table('radcheck')->where('username', 'link_priyankesh')->count())->toBe(0)
        ->and(DB::table('radcheck')->where('username', 'link_priyanka')->count())->toBe($checks)
        ->and(DB::table('radusergroup')->where('username', 'link_priyankesh')->exists())->toBeFalse()
        ->and(DB::table('account_macs')->where('username', 'link_priyanka')->count())->toBe(1)
        ->and(DB::table('reject_counters')->count())->toBe(0)
        ->and(UsagePeriod::query()->whereKey('link_priyanka')->exists())->toBeTrue()
        ->and(UsageDay::query()->where('username', 'link_priyanka')->count())->toBe(1)
        ->and($session->fresh()->username)->toBe('link_priyankesh');

    $log = Activity::query()->where('event', 'username-changed')->sole();
    expect($log->attribute_changes)->toMatchArray(['old' => ['username' => 'link_priyankesh'], 'attributes' => ['username' => 'link_priyanka']]);
    Queue::assertNothingPushed();
});

it('disconnects an online customer under the old username', function () {
    Radacct::factory()->create(['username' => 'link_priyankesh']);

    renameTo($this->account, 'link_priyanka')->assertHasNoErrors();

    Queue::assertPushed(DisconnectAccount::class, fn (DisconnectAccount $job): bool => $job->username === 'link_priyankesh'
        && $job->reason === DisconnectReason::UsernameChanged);
});

it('refuses a username another customer has, deleted ones too, or one FreeRADIUS cannot use', function (string $username) {
    Account::factory()->create(['username' => 'taken']);
    Account::factory()->create(['username' => 'gone'])->delete();

    renameTo($this->account, $username)->assertHasErrors(['new_username']);

    expect($this->account->fresh()->username)->toBe('link_priyankesh');
})->with(['taken', 'gone', 'has space', '', 'link_priyankesh']);

it('needs Edit customers', function () {
    $this->actingAs(staffHolding(Permission::ViewCustomers));

    Livewire::test('pages::customers.show', ['account' => $this->account->id])
        ->assertDontSee(__('Change username'))
        ->set('new_username', 'x1')->call('rename')->assertForbidden();
});
