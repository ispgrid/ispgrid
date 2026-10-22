<?php

use App\Enums\NotificationStatus;
use App\Models\Account;
use App\Models\NotificationLog;
use App\Models\NotificationProvider;
use App\Models\NotificationRule;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    NotificationProvider::query()->create(['provider' => 'wacrm', 'enabled' => true, 'settings' => ['base_url' => 'https://chat.example.test', 'api_key' => 'k-123456']]);
    $this->actingAs(User::factory()->create());
});

function ruleForm(): Testable
{
    return Livewire::test('pages::notifications.rule-form')
        ->set('name', '3 days before')
        ->set('trigger', 'before_expiry')
        ->set('triggerSettings.days', '3')
        ->set('provider', 'wacrm')
        ->set('template.name', 'plan_expiring')
        ->set('template.language', 'en')
        ->set('values', ['customer_name', 'expiry_date'])
        ->set('approved_text', 'Dear {1}, your plan ends on {2}.');
}

it('creates a rule that starts switched off', function () {
    $plan = Plan::factory()->create();

    ruleForm()->set('plans', [(string) $plan->id])->set('serviceTypes', ['PPP'])->call('save')->assertHasNoErrors()
        ->assertRedirect(route('notification-rules.index'));

    $rule = NotificationRule::query()->sole();
    expect($rule->trigger_settings)->toBe(['days' => 3])
        ->and($rule->template)->toBe(['name' => 'plan_expiring', 'language' => 'en'])
        ->and($rule->values)->toBe(['customer_name', 'expiry_date'])
        ->and($rule->filters)->toEqual(['plans' => [$plan->id], 'service_types' => ['PPP'], 'statuses' => []])
        ->and($rule->enabled)->toBeFalse();
});

it('checks the trigger and template fields and the values', function () {
    ruleForm()->set('triggerSettings.days', '0')->set('template.name', '')->set('values', ['amount_paid'])
        ->call('save')
        ->assertHasErrors(['triggerSettings.days', 'template.name', 'values.0']);
});

it('refuses a DLT template ID where MSG91 wants its own template ID', function () {
    NotificationProvider::query()->create(['provider' => 'msg91', 'enabled' => true, 'settings' => ['auth_key' => 'k-1']]);
    $form = ruleForm()->set('provider', 'msg91')->set('values', ['customer_name']);

    $form->set('template.template_id', '1307161483515667788')->call('save')
        ->assertHasErrors(['template.template_id'])
        ->assertSee('This is a DLT template ID. Enter the MSG91 template ID from the Templates page in MSG91 (24 letters and digits).');

    $form->set('template.template_id', '692d9d7e17dbf3327d4ed6b3')->call('save')->assertHasNoErrors();
});

it('offers only the providers that are switched on', function () {
    ruleForm()->set('provider', 'msg91')->call('save')->assertHasErrors(['provider']);
});

it('previews the message for a real customer', function () {
    Account::factory()->create(['fullname' => 'Asha Rao', 'expiry' => '2026-08-22 23:33:00']);

    ruleForm()->assertSee('Dear Asha Rao, your plan ends on 22-Aug-2026.');
});

it('shows the address to put before the pay code in the approved text', function () {
    ruleForm()->assertSee(url('p').'/')
        ->set('trigger', 'customer_created')->assertDontSee(url('p').'/');
});

it('resets the settings and drops values the new trigger cannot fill', function () {
    ruleForm()->set('trigger', 'recharge_done')
        ->assertSet('triggerSettings', [])
        ->assertSet('values', ['customer_name', 'expiry_date']);

    Livewire::test('pages::notifications.rule-form')->set('trigger', 'recharge_done')->set('values', ['amount_paid'])
        ->set('trigger', 'before_expiry')->assertSet('values', []);
});

it('edits a rule and sends a test to a number', function () {
    $rule = NotificationRule::factory()->create(['provider' => 'wacrm', 'template' => ['name' => 't1', 'language' => 'en'], 'values' => ['customer_name']]);
    Http::fake(['chat.example.test/api/v1/messages' => Http::response(['data' => ['message_id' => 'm-9']], 201)]);

    Livewire::test('pages::notifications.rule-form', ['rule' => $rule->id])
        ->assertSet('name', $rule->name)
        ->set('testNumber', '9876543210')
        ->call('sendTest')
        ->assertDispatched('toast', message: __('Test message sent.'), variant: 'success');

    $log = NotificationLog::query()->sole();
    expect($log->to)->toBe('+919876543210')
        ->and($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->account_id)->toBeNull()
        ->and($log->once_key)->toBeNull();
});

it('lists rules, switches them on and off and deletes them', function () {
    $rule = NotificationRule::factory()->create(['provider' => 'wacrm', 'name' => 'Expiring soon']);
    NotificationLog::factory()->sent()->count(2)->create(['notification_rule_id' => $rule->id]);

    Livewire::test('pages::notifications.rules')
        ->assertSeeInOrder(['Expiring soon', '3 days before expiry', 'WhatsApp', '2'])
        ->call('toggle', $rule->id);
    expect($rule->fresh()->enabled)->toBeTrue();

    NotificationProvider::query()->update(['enabled' => false]);
    Livewire::test('pages::notifications.rules')->call('toggle', $rule->id);
    expect($rule->fresh()->enabled)->toBeFalse();

    Livewire::test('pages::notifications.rules')->call('toggle', $rule->id)
        ->assertDispatched('toast', variant: 'danger');
    expect($rule->fresh()->enabled)->toBeFalse();

    Livewire::test('pages::notifications.rules')->call('delete', $rule->id);
    expect($rule->fresh()->trashed())->toBeTrue();
});

it('shows the fields of the rule\'s own provider when another provider comes first', function () {
    NotificationProvider::query()->create(['provider' => 'msg91', 'enabled' => true, 'settings' => ['auth_key' => 'k-1']]);
    $rule = NotificationRule::factory()->create(['provider' => 'wacrm', 'template' => ['name' => 'recharge_success', 'language' => 'en']]);

    Livewire::test('pages::notifications.rule-form', ['rule' => $rule->id])
        ->assertSee('Template name')
        ->assertDontSee('MSG91 template ID')
        ->assertSet('template', ['name' => 'recharge_success', 'language' => 'en']);
});
