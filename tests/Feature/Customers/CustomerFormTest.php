<?php

use App\Actions\Radius\SyncAccountToRadius;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Address;
use App\Models\AddressList;
use App\Models\AppSetting;
use App\Models\Ip;
use App\Models\Ipoe;
use App\Models\IpRange;
use App\Models\Nas;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;
use Database\Seeders\RadiusAttributeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RadiusAttributeSeeder::class);
    AppSetting::put('phone_digits', '10');
    $this->actingAs(User::factory()->create());
});

/**
 * The add form with the fields every PPPoE customer needs.
 */
function newCustomerForm(): Testable
{
    return Livewire::test('pages::customers.form')
        ->set('fullname', 'Alice Rao')
        ->set('service_type', 'PPP')
        ->set('username', 'alice')
        ->set('password', 'secret12');
}

/**
 * @return array<string, string>
 */
function customerRows(string $table, string $username): array
{
    return DB::table($table)->where('username', $username)->orderBy('id')->pluck('value', 'attribute')->all();
}

/**
 * A free address of the IP inventory (in a live range).
 */
function freeIp(string $address): Ip
{
    return Ip::factory()->create(['address' => $address]);
}

it('adds a PPPoE customer as Inactive, without plan or expiry, and writes them to FreeRADIUS', function () {
    $form = newCustomerForm()->call('save')->assertHasNoErrors();

    $account = Account::query()->sole();
    $form->assertRedirect(route('customers.show', $account->id));

    expect($account)->username->toBe('alice')->fullname->toBe('Alice Rao')
        ->status_id->toBe(AccountStatus::Inactive)->plan_id->toBeNull()->expiry->toBeNull()
        ->and(customerRows('radcheck', 'alice'))->toBe([
            'Cleartext-Password' => 'secret12',
            'Acct-Service-Type' => 'PPP',
            'Acct-Status-Id' => '2',
            'Acct-Mac-Type' => '1',
        ])
        ->and(DB::table('radusergroup')->where('username', 'alice')->count())->toBe(0)
        ->and(session('toast'))->toBe('Customer saved. If they are online, the change applies at their next login.');
});

it('binds no field to a name the browser side of Livewire uses for file uploads', function () {
    $html = Livewire::test('pages::customers.form')->html();

    expect($html)->not->toMatch('/wire:model(\.\w+)*="(upload|uploadMultiple|removeUpload|cancelUpload)"/');
});

it('leaves the checks to the server, so a bad field on a hidden tab cannot silently block the save', function () {
    Livewire::test('pages::customers.form')->assertSeeHtml('<form wire:submit="save" novalidate');
});

it('refuses a username FreeRADIUS cannot match', function (string $username) {
    newCustomerForm()->set('username', $username)->call('save')->assertHasErrors(['username']);
})->with(['alice smith', 'bc:24:11:00:30:20', "o'brien", 'ali"ce', 'name#1', 'a-very-long-name-'.str_repeat('x', 60), '']);

it('accepts the characters FreeRADIUS reads as they are', function () {
    newCustomerForm()->set('username', 'Alice.R-2_x@isp')->call('save')->assertHasNoErrors();
});

it('tells staff to restore a deleted customer instead of reusing their username', function () {
    Account::factory()->trashed()->create(['username' => 'alice']);

    newCustomerForm()->call('save')
        ->assertHasErrors(['username'])
        ->assertSee('A deleted customer uses this username. Restore it instead.');
});

it('keeps the username when a customer is edited', function () {
    $account = Account::factory()->create(['username' => 'alice', 'fullname' => 'Alice']);

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->assertSet('fullname', 'Alice')
        ->set('username', 'renamed')
        ->set('fullname', 'Alice Rao')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh())->username->toBe('alice')->fullname->toBe('Alice Rao');
});

it('does not open a deleted customer for editing', function () {
    $account = Account::factory()->trashed()->create();

    $this->get(route('customers.edit', $account->id))->assertNotFound();
});

it('has no plan, expiry or status field', function () {
    Livewire::test('pages::customers.form')
        ->assertDontSeeHtml('wire:model="plan_id"')
        ->assertDontSeeHtml('wire:model="expiry"')
        ->assertDontSeeHtml('wire:model="status');
});

it('keeps the plan and expiry of an existing customer', function () {
    $plan = Plan::factory()->create(['slug' => 'home_25']);
    $account = Account::factory()->create(['username' => 'alice', 'plan_id' => $plan->id, 'expiry' => '2030-01-31 23:59:00']);

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('fullname', 'Alice Rao')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->plan_id)->toBe($plan->id)
        ->and($account->fresh()->expiry->format('Y-m-d H:i'))->toBe('2030-01-31 23:59')
        ->and(DB::table('radusergroup')->where('username', 'alice')->value('groupname'))->toBe('home_25')
        ->and(customerRows('radcheck', 'alice')['Expiration'])->toBe('31 Jan 2030 23:59');
});

it('writes the router binding', function () {
    $router = Nas::factory()->create(['nasname' => '10.0.0.1']);

    newCustomerForm()->set('nas_id', (string) $router->id)->call('save')->assertHasNoErrors();

    expect(customerRows('radcheck', 'alice'))->toMatchArray(['Isp-Nas' => '10.0.0.1']);
});

it('refuses a deleted router or pool', function () {
    $router = Nas::factory()->trashed()->create();
    $pool = Pool::factory()->trashed()->create();

    newCustomerForm()->set('nas_id', (string) $router->id)->set('pool_id', (string) $pool->id)->call('save')
        ->assertHasErrors(['nas_id', 'pool_id']);
});

it('starts a new customer with the default pool of their type', function () {
    $pppoe = Pool::factory()->create();
    $hotspot = Pool::factory()->create();
    AppSetting::put('default_pool_ppp', (string) $pppoe->id);
    AppSetting::put('default_pool_hotspot', (string) $hotspot->id);

    Livewire::test('pages::customers.form')
        ->assertSet('pool_id', (string) $pppoe->id)
        ->set('service_type', 'HOTSPOT')
        ->assertSet('pool_id', (string) $hotspot->id);
});

it('gives a new IPoE customer the next free IP of the default range', function () {
    $range = IpRange::factory()->create();
    Ip::factory()->create(['ip_range_id' => $range->id, 'address' => '172.16.30.21']);
    Ip::factory()->create(['ip_range_id' => $range->id, 'address' => '172.16.30.20']);
    AppSetting::put('default_ip_range_ipoe', (string) $range->id);

    Livewire::test('pages::customers.form')
        ->assertSet('free_ip_range', (string) $range->id)
        ->set('service_type', 'IPOE')
        ->assertSet('ip_address', '172.16.30.20');
});

it('adds an IPoE customer found by their IP, with a password FreeRADIUS does not check', function () {
    freeIp('172.16.30.20');

    newCustomerForm()->set('service_type', 'IPOE')->set('password', '')->set('ip_address', '172.16.30.20')
        ->call('save')->assertHasNoErrors();

    expect(Ipoe::query()->sole()->only(['username', 'ip_address']))->toBe(['username' => 'alice', 'ip_address' => '172.16.30.20'])
        ->and(Account::query()->sole()->password)->toHaveLength(16)
        ->and(customerRows('radreply', 'alice'))->toBe([])
        ->and(Ip::query()->where('address', '172.16.30.20')->value('is_used'))->toBeTrue();
});

it('asks for the IP of an IPoE customer', function () {
    newCustomerForm()->set('service_type', 'IPOE')->call('save')->assertHasErrors(['ip_address']);
});

it('refuses an IP the inventory does not offer', function (string $case, string $message) {
    match ($case) {
        'missing' => null,
        'removed' => freeIp('10.9.9.9')->delete(),
        'held' => Account::factory()->create(['username' => 'bob', 'ip_id' => freeIp('10.9.9.9')->id]),
    };

    newCustomerForm()->set('ip_address', '10.9.9.9')->call('save')
        ->assertHasErrors(['ip_address'])
        ->assertSee($message);
})->with([
    ['missing', 'This address is not in the IP inventory'],
    ['removed', 'This address is not in the IP inventory'],
    ['held', 'bob already has this address.'],
]);

it('fills in the next free IP of a range', function () {
    $range = IpRange::factory()->create();

    foreach (['10.1.0.2', '10.1.0.3', '10.1.0.4', '10.1.0.5'] as $address) {
        Ip::factory()->create(['ip_range_id' => $range->id, 'address' => $address]);
    }

    Ip::query()->where('address', '10.1.0.2')->update(['is_used' => true]);
    Account::factory()->create(['ip_id' => Ip::query()->where('address', '10.1.0.3')->value('id')]);
    Ip::query()->where('address', '10.1.0.4')->firstOrFail()->delete();

    Livewire::test('pages::customers.form')
        ->set('free_ip_range', (string) $range->id)
        ->call('fillNextFreeIp')
        ->assertSet('ip_address', '10.1.0.5');
});

it('says when a range has no free address', function () {
    $range = IpRange::factory()->create();

    Livewire::test('pages::customers.form')
        ->set('free_ip_range', (string) $range->id)
        ->call('fillNextFreeIp')
        ->assertHasErrors(['ip_address'])
        ->assertSet('ip_address', '');
});

it('moves an IPoE customer to another IP and frees the old one', function () {
    $old = freeIp('172.16.30.20');
    $new = freeIp('172.16.30.21');
    $account = Account::factory()->ipoe()->create(['username' => 'erin', 'password' => 'generated123', 'ip_id' => $old->id]);
    app(SyncAccountToRadius::class)->handle($account);

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('ip_address', '172.16.30.21')
        ->call('save')
        ->assertHasNoErrors();

    expect(Ipoe::query()->sole()->ip_address)->toBe('172.16.30.21')
        ->and($old->fresh()->is_used)->toBeFalse()
        ->and($new->fresh()->is_used)->toBeTrue();
});

it('asks for a password when an IPoE customer becomes PPPoE, and stops mapping their IP', function () {
    $ip = freeIp('172.16.30.20');
    $account = Account::factory()->ipoe()->create(['username' => 'erin', 'ip_id' => $ip->id]);
    app(SyncAccountToRadius::class)->handle($account);

    $form = Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('service_type', 'PPP')
        ->set('password', '');
    $form->call('save')->assertHasErrors(['password']);

    $form->set('password', 'secret12')->call('save')->assertHasNoErrors();

    expect(Ipoe::query()->count())->toBe(0)
        ->and(customerRows('radreply', 'erin'))->toBe(['Framed-IP-Address' => '172.16.30.20']);
});

it('writes a custom speed and removes it when switched off', function () {
    newCustomerForm()->set('override_plan', true)->set('override_upload', '10M')->set('override_download', '50M')
        ->call('save')->assertHasNoErrors();

    expect(customerRows('radreply', 'alice'))->toBe(['Mikrotik-Rate-Limit' => '10M/50M']);

    Livewire::test('pages::customers.form', ['account' => Account::query()->sole()->id])
        ->set('override_plan', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(customerRows('radreply', 'alice'))->toBe([])
        ->and(Account::query()->sole()->override_upload)->toBeNull();
});

it('refuses a custom burst below the custom speed and names the field', function () {
    newCustomerForm()
        ->set('override_plan', true)
        ->set('override_upload', '10M')
        ->set('override_download', '10M')
        ->set('override_burst_upload', '5M')
        ->set('override_burst_download', '20M')
        ->set('override_threshold_upload', '8M')
        ->set('override_threshold_download', '8M')
        ->set('override_burst_time', '10')
        ->call('save')
        ->assertHasErrors(['override_burst_upload'])
        ->assertHasNoErrors(['override_burst_download']);
});

it('checks phone numbers against the digit count in the app settings', function () {
    AppSetting::put('phone_digits', '8');

    newCustomerForm()->set('mobile', '9876543210')->call('save')->assertHasErrors(['mobile'])->assertSee('Enter 8 digits.');
    newCustomerForm()->set('mobile', '98765432')->call('save')->assertHasNoErrors();
});

it('saves the installation address and removes it when emptied', function () {
    newCustomerForm()->set('address.line1', '12 MG Road')->set('address.city', 'Pune')->call('save')->assertHasNoErrors();

    $account = Account::query()->sole();
    expect($account->address->only(['line1', 'city']))->toBe(['line1' => '12 MG Road', 'city' => 'Pune']);

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('address.line1', '')
        ->set('address.city', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->address_id)->toBeNull()
        ->and(Address::query()->count())->toBe(0);
});

it('asks for the first address line when other address fields are filled', function () {
    newCustomerForm()->set('address.city', 'Pune')->call('save')->assertHasErrors(['address.line1']);
});

it('shows an alert and marks the tab that holds an error', function () {
    $form = newCustomerForm()->set('mobile', '12')->call('save');

    $form->assertHasErrors(['mobile'])
        ->assertSee('Nothing was saved. Fix the fields on the marked tabs and save again.')
        ->assertSee('(has errors)');
    expect($form->instance()->invalidTabs())->toBe(['profile']);
});

it('shows the ID fields under the labels from the app settings and hides an unlabelled one', function () {
    AppSetting::put('tax_id_label', 'GSTIN');
    AppSetting::put('business_id_label', '');

    Livewire::test('pages::customers.form')
        ->assertSee('GSTIN')
        ->assertSeeHtml('wire:model="tax_id"')
        ->assertDontSeeHtml('wire:model="business_id"');
});

it('saves custom attributes with the operator their attribute allows', function () {
    newCustomerForm()->set('custom_attributes', [
        ['attribute' => 'Framed-Route', 'value' => '203.0.113.8/29'],
        ['attribute' => 'Idle-Timeout', 'value' => '600'],
    ])->call('save')->assertHasNoErrors();

    expect(DB::table('radreply')->where('username', 'alice')->orderBy('id')->get(['attribute', 'op'])->map(fn ($row) => (array) $row)->all())
        ->toBe([['attribute' => 'Framed-Route', 'op' => '+='], ['attribute' => 'Idle-Timeout', 'op' => ':=']]);
});

it('refuses Framed-IP-Netmask on an IPoE customer only', function () {
    freeIp('172.16.30.20');
    $attributes = [['attribute' => 'Framed-IP-Netmask', 'value' => '255.255.255.0']];

    newCustomerForm()->set('service_type', 'IPOE')->set('ip_address', '172.16.30.20')->set('custom_attributes', $attributes)
        ->call('save')
        ->assertHasErrors(['custom_attributes.0.attribute']);

    newCustomerForm()->set('custom_attributes', $attributes)->call('save')->assertHasNoErrors();
});

it('saves nothing when the FreeRADIUS rows cannot be written', function () {
    $account = Account::factory()->create(['fullname' => 'Alice']);
    $list = AddressList::factory()->create();
    $this->mock(SyncAccountToRadius::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('radcheck is not writable'));

    expect(fn () => Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('fullname', 'Alice Rao')
        ->set('address_list_ids', [(string) $list->id])
        ->set('address.line1', '12 MG Road')
        ->call('save'))->toThrow(RuntimeException::class);

    expect($account->fresh()->fullname)->toBe('Alice')
        ->and($account->addressLists()->count())->toBe(0)
        ->and(Address::query()->count())->toBe(0);
});

it('tells staff when another save took the address first', function () {
    $ip = freeIp('10.9.9.9');
    Event::listen('eloquent.creating: '.Account::class, function (Account $account) use ($ip): void {
        if ($account->username === 'alice') {
            Account::factory()->create(['username' => 'bob', 'ip_id' => $ip->id]);
        }
    });

    newCustomerForm()->set('ip_address', '10.9.9.9')->call('save')
        ->assertHasErrors(['ip_address'])
        ->assertSee('This address was just given to another customer. Pick another.');

    expect(Account::query()->count())->toBe(0);
});

it('records the address and address lists in the activity log, never the password', function () {
    $list = AddressList::factory()->create(['name' => 'vip']);

    newCustomerForm()->set('address.line1', '12 MG Road')->set('address_list_ids', [(string) $list->id])
        ->call('save')->assertHasNoErrors();

    $logged = Activity::query()->get()
        ->map(fn (Activity $activity) => json_encode([$activity->attribute_changes, $activity->properties]))
        ->implode(' ');

    expect($logged)->toContain('12 MG Road')->toContain('vip')->not->toContain('secret12');
});

it('refuses a password FreeRADIUS would unquote or expand', function (string $password) {
    newCustomerForm()->set('password', $password)->call('save')->assertHasErrors(['password']);
})->with(['"secret12"', "'secret12'", '`date`', 'pass"word', 'back\slash']);

it('refuses a tampered password on an IPoE customer', function () {
    freeIp('172.16.30.20');

    newCustomerForm()->set('service_type', 'IPOE')->set('ip_address', '172.16.30.20')->set('password', "o'brien")
        ->call('save')
        ->assertHasErrors(['password']);
});

it('clears the auto-filled IPoE address when a new customer switches to another type', function () {
    $range = IpRange::factory()->create();
    Ip::factory()->create(['ip_range_id' => $range->id, 'address' => '172.16.30.20']);
    AppSetting::put('default_ip_range_ipoe', (string) $range->id);

    Livewire::test('pages::customers.form')
        ->set('service_type', 'IPOE')
        ->assertSet('ip_address', '172.16.30.20')
        ->set('service_type', 'PPP')
        ->assertSet('ip_address', '');
});

it('keeps an address staff typed when a new customer switches type', function () {
    Livewire::test('pages::customers.form')
        ->set('service_type', 'IPOE')
        ->set('ip_address', '10.9.9.9')
        ->set('service_type', 'PPP')
        ->assertSet('ip_address', '10.9.9.9');
});

it('forgets the bound devices when a customer changes type', function () {
    $account = Account::factory()->create(['username' => 'alice', 'password' => 'secret12']);
    DB::table('account_macs')->insert([
        ['username' => 'alice', 'mac_norm' => 'AA0000000001'],
        ['username' => 'alice', 'mac_norm' => 'AA0000000002'],
    ]);
    freeIp('172.16.30.20');

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('service_type', 'IPOE')
        ->set('ip_address', '172.16.30.20')
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('account_macs')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'devices-reset')->sole()->properties['macs'])
        ->toBe(['AA:00:00:00:00:01', 'AA:00:00:00:00:02']);
});

it('keeps the bound devices when the type stays the same', function () {
    $account = Account::factory()->create(['username' => 'alice', 'password' => 'secret12']);
    DB::table('account_macs')->insert(['username' => 'alice', 'mac_norm' => 'AA0000000001']);

    Livewire::test('pages::customers.form', ['account' => $account->id])->set('fullname', 'Alice Rao')->call('save')->assertHasNoErrors();

    expect(DB::table('account_macs')->count())->toBe(1);
});

it('does not carry the hidden IPoE password over when an IPoE customer becomes PPPoE', function () {
    $account = Account::factory()->ipoe()->create(['password' => 'generated123', 'ip_id' => freeIp('172.16.30.20')->id]);

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->assertSet('password', '')
        ->set('service_type', 'PPP')
        ->call('save')
        ->assertHasErrors(['password']);
});

it('keeps the password of an IPoE customer saved again', function () {
    $account = Account::factory()->ipoe()->create(['password' => 'generated123', 'ip_id' => freeIp('172.16.30.20')->id]);

    Livewire::test('pages::customers.form', ['account' => $account->id])->set('fullname', 'Erin Das')->call('save')->assertHasNoErrors();

    expect($account->fresh()->password)->toBe('generated123');
});

it('asks for a range before picking the next free IP', function () {
    Livewire::test('pages::customers.form')
        ->call('fillNextFreeIp')
        ->assertHasErrors(['ip_address'])
        ->assertSee('Choose a range first.');
});

it('logs a new customer as one created entry with the address and lists', function () {
    $list = AddressList::factory()->create(['name' => 'vip']);

    newCustomerForm()->set('address.line1', '12 MG Road')->set('address_list_ids', [(string) $list->id])
        ->call('save')->assertHasNoErrors();

    $activities = Activity::query()->where('subject_type', (new Account)->getMorphClass())->get();
    $attributes = collect($activities->sole()->attribute_changes)->toArray()['attributes'];

    expect($activities->sole()->event)->toBe('created')
        ->and($attributes)->toMatchArray(['username' => 'alice', 'address' => '12 MG Road', 'address_lists' => 'vip'])
        ->and($attributes)->not->toHaveKey('password');
});

it('records that the password changed, without the password', function () {
    $account = Account::factory()->create(['username' => 'alice', 'password' => 'oldpass12']);

    Livewire::test('pages::customers.form', ['account' => $account->id])->set('password', 'newpass34')->call('save')->assertHasNoErrors();

    $activity = Activity::query()->where('event', 'password-changed')->sole();

    expect($activity->subject_id)->toBe($account->id)
        ->and(json_encode([$activity->attribute_changes, $activity->properties]))->not->toContain('pass12')->not->toContain('pass34');
});

it('records no password change when the password stays the same', function () {
    $account = Account::factory()->create(['password' => 'oldpass12']);

    Livewire::test('pages::customers.form', ['account' => $account->id])->set('fullname', 'Alice Rao')->call('save')->assertHasNoErrors();

    expect(Activity::query()->where('event', 'password-changed')->count())->toBe(0);
});
