<?php

use App\Actions\Radius\SyncPlanToRadius;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\AddressList;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;
use Database\Seeders\RadiusAttributeSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RadiusAttributeSeeder::class);
    $this->actingAs(User::factory()->create());
});

/**
 * The add form with the fields every plan needs.
 */
function newPlanForm(): Testable
{
    return Livewire::test('pages::plans.form')
        ->set('name', 'Home 25')
        ->set('slug', 'home_25')
        ->set('validity', '30')
        ->set('price', '599')
        ->set('upload', '25M')
        ->set('download', '25M');
}

/**
 * @return array<string, string>
 */
function planRows(string $slug): array
{
    return DB::table('radgroupreply')->where('groupname', $slug)->orderBy('id')->pluck('value', 'attribute')->all();
}

it('adds a plan and writes it to FreeRADIUS', function () {
    newPlanForm()->call('save')->assertHasNoErrors()->assertRedirect(route('plans.index'));

    expect(Plan::query()->sole())->slug->toBe('home_25')->name->toBe('Home 25')
        ->and(planRows('home_25'))->toBe(['Mikrotik-Rate-Limit' => '25M/25M'])
        ->and(session('toast'))->toBe('Plan saved. Customers who are online get the change at their next login.');
});

it('refuses a slug FreeRADIUS cannot use as a group name', function (string $slug) {
    newPlanForm()->set('slug', $slug)->call('save')->assertHasErrors(['slug']);
})->with(['Home', 'home 25', 'home.25', "o'brien", '']);

it('tells staff to restore a deleted plan instead of reusing its slug', function () {
    Plan::factory()->trashed()->create(['slug' => 'home_25']);

    newPlanForm()->call('save')
        ->assertHasErrors(['slug'])
        ->assertSee('A deleted plan uses this slug. Restore it instead.');
});

it('keeps the slug when a plan is edited', function () {
    $plan = Plan::factory()->create(['slug' => 'home_25', 'name' => 'Home']);

    Livewire::test('pages::plans.form', ['plan' => $plan->id])
        ->assertSet('name', 'Home')
        ->set('slug', 'renamed')
        ->set('name', 'Home plus')
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh())->slug->toBe('home_25')->name->toBe('Home plus');
});

it('does not open a deleted plan for editing', function () {
    $plan = Plan::factory()->trashed()->create();

    $this->get(route('plans.edit', $plan->id))->assertNotFound();
});

it('refuses a speed without a unit', function () {
    newPlanForm()->set('download', '25m')->call('save')->assertHasErrors(['download']);

    expect(Plan::query()->count())->toBe(0);
});

it('refuses a burst below the normal speed and names the field', function () {
    newPlanForm()
        ->set('burst_upload', '10M')->set('burst_download', '30M')
        ->set('threshold_upload', '20M')->set('threshold_download', '20M')
        ->set('burst_time', '10')
        ->call('save')
        ->assertHasErrors(['burst_upload'])
        ->assertHasNoErrors(['burst_download']);
});

it('writes burst, priority and minimum as one rate limit', function () {
    newPlanForm()
        ->set('burst_upload', '30M')->set('burst_download', '30M')
        ->set('threshold_upload', '20M')->set('threshold_download', '20M')
        ->set('burst_time', '10')->set('priority', '8')
        ->set('min_upload', '5M')->set('min_download', '5M')
        ->call('save')
        ->assertHasNoErrors();

    expect(planRows('home_25'))->toBe(['Mikrotik-Rate-Limit' => '25M/25M 30M/30M 20M/20M 10/10 8 5M/5M']);
});

it('shows an alert and marks the tab that holds an error', function () {
    $form = newPlanForm()->set('daily_time_hours', '25')->call('save');

    $form->assertHasErrors(['daily_time_hours'])
        ->assertSee('Nothing was saved. Fix the fields on the marked tabs and save again.')
        ->assertSee('(has errors)');
    expect($form->instance()->invalidTabs())->toBe(['limits']);
});

it('writes limits in bytes and seconds and the fair usage speed', function () {
    newPlanForm()
        ->set('limit_after', '100')->set('daily_time_hours', '4')
        ->set('is_fup', true)->set('fup_upload', '2M')->set('fup_download', '2M')
        ->call('save')
        ->assertHasNoErrors();

    expect(planRows('home_25'))->toBe([
        'Mikrotik-Rate-Limit' => '25M/25M',
        'Isp-Data-Limit' => '107374182400',
        'Isp-Daily-Time' => '14400',
        'Isp-Fup-Rate' => '2M/2M',
    ]);
});

it('asks for a limit before fair usage can be switched on', function () {
    newPlanForm()
        ->set('is_fup', true)->set('fup_upload', '2M')->set('fup_download', '2M')
        ->call('save')
        ->assertHasErrors(['is_fup']);
});

it('writes a boost time slot with the days in week order', function () {
    newPlanForm()
        ->set('slot_mode', 'boost')->set('slot_days', ['Sa', 'Mo'])
        ->set('slot_from', '22')->set('slot_to', '6')
        ->set('slot_upload', '50M')->set('slot_download', '50M')
        ->call('save')
        ->assertHasNoErrors();

    expect(Plan::query()->sole()->slot_days)->toBe('Mo,Sa')
        ->and(planRows('home_25'))->toBe([
            'Mikrotik-Rate-Limit' => '25M/25M',
            'Isp-Slot-Mode' => 'boost',
            'Isp-Slot-Days' => 'Mo,Sa',
            'Isp-Slot-From' => '22',
            'Isp-Slot-To' => '6',
            'Isp-Slot-Rate' => '50M/50M',
        ]);
});

it('asks for the days, hours and boost speed of a time slot', function () {
    newPlanForm()->set('slot_mode', 'boost')->call('save')
        ->assertHasErrors(['slot_days', 'slot_from', 'slot_to', 'slot_upload', 'slot_download']);
});

it('removes the time slot rows when the time slot is switched off', function () {
    $plan = Plan::factory()->boostSlot()->create(['slug' => 'night']);
    app(SyncPlanToRadius::class)->handle($plan);

    Livewire::test('pages::plans.form', ['plan' => $plan->id])->set('slot_mode', '')->call('save')->assertHasNoErrors();

    expect(array_keys(planRows('night')))->toBe(['Mikrotik-Rate-Limit'])
        ->and($plan->fresh()->slot_days)->toBeNull();
});

it('writes the pool and the address lists chosen on the Network tab', function () {
    $pool = Pool::factory()->create(['name' => 'pppoe']);
    $list = AddressList::factory()->create(['name' => 'gaming']);

    newPlanForm()
        ->set('pool_id', (string) $pool->id)
        ->set('address_list_ids', [(string) $list->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(Plan::query()->sole()->addressLists->pluck('name')->all())->toBe(['gaming'])
        ->and(planRows('home_25'))->toBe([
            'Mikrotik-Rate-Limit' => '25M/25M',
            'Framed-Pool' => 'pppoe',
            'Isp-Plan-Address-List' => 'gaming',
        ]);
});

it('refuses a deleted pool or address list', function () {
    $pool = Pool::factory()->trashed()->create();
    $list = AddressList::factory()->trashed()->create();

    newPlanForm()
        ->set('pool_id', (string) $pool->id)
        ->set('address_list_ids', [(string) $list->id])
        ->call('save')
        ->assertHasErrors(['pool_id', 'address_list_ids.0']);
});

it('warns when a plan with Hotspot or IPoE customers gets a pool', function () {
    $plan = Plan::factory()->create();
    Account::factory()->create(['plan_id' => $plan->id, 'service_type' => ServiceType::Hotspot]);
    $pool = Pool::factory()->create();

    Livewire::test('pages::plans.form', ['plan' => $plan->id])
        ->assertDontSee('1:1 NAT')
        ->set('pool_id', (string) $pool->id)
        ->assertSee('1:1 NAT');
});

it('saves custom attributes with the operator their attribute allows', function () {
    newPlanForm()
        ->call('addAttribute')
        ->set('custom_attributes.0.attribute', 'Idle-Timeout')->set('custom_attributes.0.value', '300')
        ->call('addAttribute')
        ->set('custom_attributes.1.attribute', 'Framed-Route')->set('custom_attributes.1.value', '10.9.0.0/24 0.0.0.0 1')
        ->call('save')
        ->assertHasNoErrors();

    $rows = DB::table('radgroupreply')->where('groupname', 'home_25')->orderBy('id')->get()
        ->map(fn (object $row): array => [$row->attribute, $row->op, $row->value])->all();

    expect(array_slice($rows, 1))->toBe([
        ['Idle-Timeout', '=', '300'],
        ['Framed-Route', '+=', '10.9.0.0/24 0.0.0.0 1'],
    ]);
});

it('refuses a custom attribute FreeRADIUS would fail on', function (string $attribute, string $value, string $field) {
    newPlanForm()
        ->call('addAttribute')
        ->set('custom_attributes.0.attribute', $attribute)->set('custom_attributes.0.value', $value)
        ->call('save')
        ->assertHasErrors([$field]);
})->with([
    'not on the allowlist' => ['Framed-Pool', 'pppoe', 'custom_attributes.0.attribute'],
    'wrong type' => ['Idle-Timeout', 'five minutes', 'custom_attributes.0.value'],
]);

it('allows a single-value attribute only once', function () {
    newPlanForm()
        ->call('addAttribute')
        ->set('custom_attributes.0.attribute', 'Idle-Timeout')->set('custom_attributes.0.value', '300')
        ->call('addAttribute')
        ->set('custom_attributes.1.attribute', 'Idle-Timeout')->set('custom_attributes.1.value', '600')
        ->call('save')
        ->assertHasErrors(['custom_attributes.1.attribute'])
        ->assertHasNoErrors(['custom_attributes.0.attribute']);
});

it('records changed address lists in the activity log', function () {
    $plan = Plan::factory()->create();
    $list = AddressList::factory()->create(['name' => 'gaming']);

    Livewire::test('pages::plans.form', ['plan' => $plan->id])
        ->set('address_list_ids', [(string) $list->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(collect(Activity::query()->latest('id')->firstOrFail()->attribute_changes)->toArray())
        ->toBe(['old' => ['address_lists' => ''], 'attributes' => ['address_lists' => 'gaming']]);
});

it('saves nothing when the FreeRADIUS rows cannot be written', function () {
    $plan = Plan::factory()->create(['name' => 'Home']);
    $list = AddressList::factory()->create();
    $this->mock(SyncPlanToRadius::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('radgroupreply is not writable'));

    expect(fn () => Livewire::test('pages::plans.form', ['plan' => $plan->id])
        ->set('name', 'Home plus')
        ->set('address_list_ids', [(string) $list->id])
        ->call('save'))->toThrow(RuntimeException::class);

    expect($plan->fresh()->name)->toBe('Home')
        ->and($plan->addressLists()->count())->toBe(0);
});
