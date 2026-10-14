<?php

use App\Enums\NotificationStatus;
use App\Enums\Permission;
use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\NotificationLog;
use App\Models\NotificationRule;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->actingAs(User::factory()->create());
});

it('lists messages newest first with their status and error', function () {
    $asha = Account::factory()->create(['username' => 'asha']);
    NotificationLog::factory()->sent()->create(['account_id' => $asha->id, 'created_at' => now()->subHour()]);
    NotificationLog::factory()->failed('bad_request: template not found')->create(['account_id' => $asha->id]);

    Livewire::test('pages::notifications.log')
        ->assertSeeInOrder(['asha', 'Failed', 'bad_request: template not found', 'asha', 'Sent']);
});

it('filters by customer, status and rule', function () {
    $rule = NotificationRule::factory()->create(['name' => 'Expiring']);
    NotificationLog::factory()->create(['account_id' => Account::factory()->create(['username' => 'asha'])->id, 'notification_rule_id' => $rule->id]);
    NotificationLog::factory()->failed()->create(['account_id' => Account::factory()->create(['username' => 'bina'])->id]);

    Livewire::test('pages::notifications.log')->set('status', 'failed')->assertSee('bina')->assertDontSee('asha');
    Livewire::test('pages::notifications.log')->set('ruleId', (string) $rule->id)->assertSee('asha')->assertDontSee('bina');
    // Last: the query string stays set for every later Livewire::test in this test.
    Livewire::withQueryParams(['customer' => 'asha'])->test('pages::notifications.log')->assertSee('asha')->assertDontSee('bina');
});

it('retries a failed message for roles that manage rules', function () {
    Queue::fake();
    $log = NotificationLog::factory()->failed()->create();

    Livewire::test('pages::notifications.log')->call('retry', $log->id);
    expect($log->fresh()->status)->toBe(NotificationStatus::Queued);
    Queue::assertPushed(SendNotification::class);

    $this->actingAs(staffHolding(Permission::ViewNotificationLog));
    Livewire::test('pages::notifications.log')->assertDontSee(__('Retry'))->call('retry', $log->id)->assertForbidden();
});

it('shows the last messages on the customer page to roles with the log box', function () {
    $account = Account::factory()->create();
    NotificationLog::factory()->sent()->create(['account_id' => $account->id, 'values' => ['Asha'], 'preview' => 'Dear Asha, renew soon.']);

    Livewire::test('pages::customers.show', ['account' => $account->id])
        ->assertSee(__('Messages'))
        ->assertSee('Dear Asha, renew soon.');

    $this->actingAs(staffHolding(Permission::ViewCustomers));
    Livewire::test('pages::customers.show', ['account' => $account->id])->assertDontSee('Dear Asha, renew soon.');
});
