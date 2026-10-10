<?php

use App\Actions\Customers\RechargeAccount;
use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * An account sync that hits a deadlock on its first call, as two staff saving at once can, then works.
 */
function accountSyncThatDeadlocksOnce(): void
{
    app()->instance(SyncAccountToRadius::class, new class extends SyncAccountToRadius
    {
        private bool $failed = false;

        public function handle(Account $account, ?int $previousIpId = null): bool
        {
            if (! $this->failed) {
                $this->failed = true;

                throw new QueryException('mysql', 'delete from radcheck', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
            }

            return parent::handle($account, $previousIpId);
        }
    });
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('saves the customer and their FreeRADIUS rows together when the save is retried after a deadlock', function () {
    $account = Account::factory()->create(['username' => 'alice', 'password' => 'oldpass12']);
    accountSyncThatDeadlocksOnce();

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('password', 'newpass12')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->password)->toBe('newpass12')
        ->and(DB::table('radcheck')->where(['username' => 'alice', 'attribute' => 'Cleartext-Password'])->value('value'))->toBe('newpass12');
});

it('changes the status when the change is retried after a deadlock', function () {
    $account = Account::factory()->create(['username' => 'alice']);
    accountSyncThatDeadlocksOnce();

    Livewire::test('pages::customers.show', ['account' => $account->id])
        ->set('status', '2')
        ->set('remark', 'Unpaid')
        ->call('changeStatus')
        ->assertHasNoErrors();

    expect($account->fresh()->status_id->value)->toBe(2)
        ->and(DB::table('radcheck')->where(['username' => 'alice', 'attribute' => 'Acct-Status-Id'])->value('value'))->toBe('2');
});

it('recharges when the recharge is retried after a deadlock', function () {
    $account = Account::factory()->create(['username' => 'alice', 'status_id' => AccountStatus::Inactive]);
    $plan = Plan::factory()->create(['slug' => 'home']);
    accountSyncThatDeadlocksOnce();

    app(RechargeAccount::class)->handle($account->id, $plan->id, now()->addDays(30), '599', null);

    expect($account->fresh())->plan_id->toBe($plan->id)->status_id->toBe(AccountStatus::Active)
        ->and(Recharge::query()->count())->toBe(1)
        ->and(DB::table('radusergroup')->where('username', 'alice')->value('groupname'))->toBe('home')
        ->and(DB::table('radcheck')->where(['username' => 'alice', 'attribute' => 'Acct-Status-Id'])->value('value'))->toBe('1');
});
