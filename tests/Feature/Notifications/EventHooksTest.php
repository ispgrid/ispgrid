<?php

use App\Actions\Customers\RechargeAccount;
use App\Actions\Import\ImportCustomers;
use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\NotificationRegistry;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusAttributeSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Notifications\FakeProvider;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RadiusAttributeSeeder::class]);
    AppSetting::put('phone_digits', '10');
    app(NotificationRegistry::class)->registerProvider(FakeProvider::class);
    NotificationProvider::query()->create(['provider' => 'fake', 'enabled' => true, 'settings' => ['token' => 'tok12345']]);
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

it('messages the customer when a recharge is saved', function () {
    NotificationRule::factory()->enabled()->create(['trigger' => 'recharge_done', 'trigger_settings' => [], 'values' => ['amount_paid']]);
    $account = Account::factory()->create(['mobile' => '9876543210']);
    $plan = Plan::factory()->create();

    $recharge = app(RechargeAccount::class)->handle($account->id, $plan->id, now()->addMonth(), '499', null);

    $log = NotificationLog::query()->sole();
    expect($log->once_key)->toBe("rule:{$log->notification_rule_id}:recharge:{$recharge->id}")
        ->and($log->values)->toBe(['499.00']);
    Queue::assertPushed(SendNotification::class, 1);
});

it('messages a customer staff add in the form, but not ones from an import', function () {
    NotificationRule::factory()->enabled()->create(['trigger' => 'customer_created', 'trigger_settings' => [], 'values' => ['username']]);

    Livewire::test('pages::customers.form')
        ->set('fullname', 'Bina Rao')->set('service_type', 'PPP')->set('username', 'bina')->set('password', 'secret12')
        ->set('mobile', '9876543210')
        ->call('save')
        ->assertHasNoErrors();

    $columns = array_fill_keys(app(ImportCustomers::class)->columns(), '');
    app(ImportCustomers::class)->import([['line' => 2, 'values' => [...$columns, 'username' => 'imported', 'password' => 'secret12', 'service_type' => 'PPP', 'fullname' => 'Imported One', 'mobile' => '9123456789']]]);

    expect(NotificationLog::query()->sole()->values)->toBe(['bina']);
});

it('saves the No reminders switch from the form', function () {
    $account = Account::factory()->create();

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->assertSet('notifications_off', false)
        ->set('notifications_off', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->notifications_off)->toBeTrue();
});
