<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\CustomerService;
use App\Models\Ip;
use App\Models\Item;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Models\User;
use App\Notifications\NotificationRegistry;
use App\Notifications\Notifier;
use App\Notifications\Triggers\ServiceExpired;
use App\Notifications\Triggers\ServiceExpiresSoon;
use Carbon\CarbonImmutable;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Notifications\FakeProvider;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    app(NotificationRegistry::class)->registerProvider(FakeProvider::class);
    NotificationProvider::query()->create(['provider' => 'fake', 'enabled' => true, 'settings' => ['token' => 'tok12345']]);
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-08-19 10:00'));
    $this->account = Account::factory()->create(['fullname' => 'Asha', 'mobile' => '9876543210']);
});

function serviceRule(string $trigger, array $settings = []): NotificationRule
{
    return NotificationRule::factory()->enabled()->create([
        'trigger' => $trigger, 'trigger_settings' => $settings,
        'values' => ['customer_name', 'service', 'service_expiry', 'static_ip'],
        'approved_text' => 'Dear {1}, {2} ends on {3} {4}.',
    ]);
}

function sendDue(): int
{
    return app(Notifier::class)->dispatchScheduled(CarbonImmutable::now());
}

it('sends one message per service expiring on the day, once', function () {
    serviceRule('service_expiring', ['days' => 3]);
    $ip = Ip::factory()->create(['address' => '10.0.0.5']);
    CustomerService::factory()->for($this->account)->for(Item::factory()->staticIp())->create(['ip_id' => $ip->id, 'paid_until' => '2026-08-22 18:00:00']);
    CustomerService::factory()->for($this->account)->for(Item::factory()->service()->state(['name' => 'OTT pack']))->create(['paid_until' => '2026-08-22 09:00:00']);
    CustomerService::factory()->for($this->account)->create(['paid_until' => '2026-08-23 09:00:00']);
    CustomerService::factory()->for($this->account)->create(['paid_until' => '2026-08-22 09:00:00', 'ended_at' => now()]);

    expect(sendDue())->toBe(2)
        ->and(sendDue())->toBe(0);

    expect(NotificationLog::query()->orderBy('id')->pluck('values')->all())->toEqualCanonicalizing([
        ['Asha', 'Static IP', '22-Aug-2026', '10.0.0.5'],
        ['Asha', 'OTT pack', '22-Aug-2026', ''],
    ]);
});

it('sends again for the next period after a renewal', function () {
    serviceRule('service_expiring', ['days' => 3]);
    $service = CustomerService::factory()->for($this->account)->create(['paid_until' => '2026-08-22 18:00:00']);
    sendDue();

    $service->update(['paid_until' => '2026-09-22 18:00:00']);
    expect(sendDue())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:00'));
    expect(sendDue())->toBe(1);
});

it('sends service expired once, also the morning after a late expiry', function () {
    serviceRule('service_expired');
    CustomerService::factory()->for($this->account)->create(['paid_until' => '2026-08-18 22:30:00']);
    CustomerService::factory()->for($this->account)->create(['paid_until' => '2026-08-16 08:00:00']);
    CustomerService::factory()->for($this->account)->create(['paid_until' => '2026-08-19 11:00:00']);

    expect(sendDue())->toBe(1)
        ->and(sendDue())->toBe(0);
});

it('applies the rule filters and the no reminders switch through the customer', function () {
    serviceRule('service_expiring', ['days' => 3])->update(['filters' => ['statuses' => [AccountStatus::Active->value]]]);
    $suspended = Account::factory()->suspended()->create();
    $quiet = Account::factory()->create(['notifications_off' => true]);
    foreach ([$suspended, $quiet] as $account) {
        CustomerService::factory()->for($account)->create(['paid_until' => '2026-08-22 18:00:00']);
    }

    expect(sendDue())->toBe(0);
});

it('describes the triggers and offers the service placeholders', function () {
    expect(ServiceExpiresSoon::summary(['days' => 3]))->toBe('3 days before a service expires')
        ->and(ServiceExpiresSoon::summary(['days' => 1]))->toBe('1 day before a service expires')
        ->and(ServiceExpired::placeholders())->toContain('service', 'service_expiry', 'static_ip')
        ->and(array_keys(app(NotificationRegistry::class)->triggers()))->toContain('service_expiring', 'service_expired');

    $this->actingAs(User::factory()->create());
    Livewire::test('pages::notifications.rule-form')->assertSee(__('Service expires soon'))->assertSee(__('Service expired'));
});
