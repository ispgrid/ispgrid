<?php

use App\Actions\Radius\SyncPlanToRadius;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * A sync that hits a deadlock on its first call, as two staff saving neighbouring plans can, then works.
 */
function syncThatDeadlocksOnce(): void
{
    app()->instance(SyncPlanToRadius::class, new class extends SyncPlanToRadius
    {
        private bool $failed = false;

        public function handle(Plan $plan): void
        {
            if (! $this->failed) {
                $this->failed = true;

                throw new QueryException('mysql', 'delete from radgroupreply', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
            }

            parent::handle($plan);
        }
    });
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('saves the plan and its FreeRADIUS rows together when the save is retried after a deadlock', function () {
    $plan = Plan::factory()->create(['slug' => 'home', 'upload' => '10M', 'download' => '10M']);
    syncThatDeadlocksOnce();

    Livewire::test('pages::plans.form', ['plan' => $plan->id])
        ->set('upload_speed', '50M')
        ->set('download_speed', '50M')
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->upload)->toBe('50M')
        ->and(DB::table('radgroupreply')->where('groupname', 'home')->value('value'))->toBe('50M/50M');
});

it('restores a plan when the restore is retried after a deadlock', function () {
    $plan = Plan::factory()->trashed()->create(['slug' => 'old', 'upload' => '5M', 'download' => '5M']);
    syncThatDeadlocksOnce();

    Livewire::test('pages::plans.index')->call('restore', $plan->id)->assertDispatched('toast', variant: 'success');

    expect($plan->fresh()->trashed())->toBeFalse()
        ->and(DB::table('radgroupreply')->where('groupname', 'old')->value('value'))->toBe('5M/5M');
});
