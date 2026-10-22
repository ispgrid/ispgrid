<?php

use App\Actions\Import\ImportCustomers;
use App\Enums\AccountStatus;
use App\Enums\Permission;
use App\Exceptions\ImportFailed;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\Ip;
use App\Models\Ipoe;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\Recharge;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    AppSetting::put('phone_digits', '10');
    $this->actingAs(User::factory()->create());
});

/**
 * Customer rows as CsvFile gives them (every column present), numbered from spreadsheet row 2. Each argument
 * overrides a valid PPPoE base row.
 *
 * @param  array<string, string>  ...$rows
 * @return list<array{line: int, values: array<string, string>}>
 */
function importCustomerRows(array ...$rows): array
{
    $blank = array_fill_keys(app(ImportCustomers::class)->columns(), '');
    $base = ['username' => 'alice', 'password' => 'secret12', 'service_type' => 'PPP', 'fullname' => 'Alice Rao'];

    return array_map(
        fn (array $values, int $index): array => ['line' => $index + 2, 'values' => [...$blank, ...$base, ...$values]],
        $rows,
        array_keys($rows),
    );
}

/**
 * @param  array<string, string>  ...$rows
 * @return list<string>
 */
function customerCheck(array ...$rows): array
{
    return errorLines(app(ImportCustomers::class)->check(importCustomerRows(...$rows)));
}

/**
 * @param  list<array{line: int, values: array<string, string>}>  $rows
 * @return list<string>
 */
function failedCustomerImport(array $rows): array
{
    try {
        app(ImportCustomers::class)->import($rows);
    } catch (ImportFailed $failed) {
        return errorLines($failed->errors);
    }

    throw new RuntimeException('The import did not fail.');
}

/**
 * @return list<array{attribute: string, op: string, value: string}>
 */
function radcheckOf(string $username): array
{
    return DB::table('radcheck')->where('username', $username)->orderBy('attribute')->get(['attribute', 'op', 'value'])
        ->map(fn (object $row): array => (array) $row)->all();
}

/**
 * @return list<array{attribute: string, op: string, value: string}>
 */
function radreplyOf(string $username): array
{
    return DB::table('radreply')->where('username', $username)->orderBy('attribute')->orderBy('value')->get(['attribute', 'op', 'value'])
        ->map(fn (object $row): array => (array) $row)->all();
}

it('imports a customer as Inactive without a plan, with the FreeRADIUS rows the form writes', function () {
    app(ImportCustomers::class)->import(importCustomerRows([]));

    Livewire::test('pages::customers.form')
        ->set('fullname', 'Bob Rao')->set('service_type', 'PPP')->set('username', 'bob')->set('password', 'secret12')
        ->call('save')->assertHasNoErrors();

    expect(Account::query()->where('username', 'alice')->sole())
        ->status_id->toBe(AccountStatus::Inactive)->plan_id->toBeNull()->expiry->toBeNull()->usage_since->toBeNull()
        ->and(radcheckOf('alice'))->toBe(radcheckOf('bob'))
        ->and(radreplyOf('alice'))->toBe(radreplyOf('bob'));
});

it('gives a customer the plan, expiry and status from the file, with the usage period starting now', function () {
    $this->travelTo(Carbon::parse('2026-10-10 14:30:00'));
    $plan = Plan::factory()->create(['slug' => 'home_25']);

    app(ImportCustomers::class)->import(importCustomerRows(['plan_slug' => 'HOME_25', 'expiry' => '2026-11-30 23:59', 'status' => 'active']));

    expect(Account::query()->sole())
        ->plan_id->toBe($plan->id)
        ->status_id->toBe(AccountStatus::Active)
        ->expiry->format('Y-m-d H:i')->toBe('2026-11-30 23:59')
        ->usage_since->format('Y-m-d H:i')->toBe('2026-10-10 14:30')
        ->and(DB::table('radusergroup')->where('username', 'alice')->value('groupname'))->toBe('home_25')
        ->and(DB::table('radcheck')->where(['username' => 'alice', 'attribute' => 'Expiration'])->value('value'))->toBe('30 Nov 2026 23:59')
        ->and(Recharge::query()->count())->toBe(0);
});

it('reads a date-only expiry as midnight', function () {
    Plan::factory()->create(['slug' => 'home_25']);

    app(ImportCustomers::class)->import(importCustomerRows(['plan_slug' => 'home_25', 'expiry' => '2026-11-30']));

    expect(Account::query()->sole()->expiry->format('Y-m-d H:i'))->toBe('2026-11-30 00:00');
});

it('refuses an expiry a spreadsheet reformatted, never misreading it', function (string $expiry) {
    Plan::factory()->create(['slug' => 'home_25']);

    expect(customerCheck(['plan_slug' => 'home_25', 'expiry' => $expiry]))
        ->toBe(['2 expiry: Use YYYY-MM-DD HH:MM, e.g. 2026-11-30 23:59.']);
})->with(['30/11/2026', '11/30/26 23:59', '2026-02-30', '2026-11-30 24:00']);

it('checks plan, expiry and status together', function (array $values, array $lines) {
    Plan::factory()->create(['slug' => 'home_25']);

    expect(customerCheck($values))->toBe($lines);
})->with([
    'plan without expiry' => [['plan_slug' => 'home_25'], ['2 expiry: Give the expiry for this plan.']],
    'expiry without plan' => [['expiry' => '2026-11-30 23:59'], ['2 expiry: Give a plan with the expiry.']],
    'Active without plan' => [['status' => 'Active'], ['2 status: An Active customer needs a plan and an expiry.']],
    'Suspended without plan' => [['status' => 'Suspended'], []],
    'unknown plan' => [['plan_slug' => 'gold', 'expiry' => '2026-11-30 23:59'], ['2 plan_slug: No plan with this slug.']],
    'unknown status' => [['status' => 'Terminated'], ['2 status: Use Active, Inactive or Suspended.']],
]);

it('imports an IPoE customer by their IP, with a generated password, one device and no pool', function () {
    AppSetting::put('default_pool_ppp', (string) Pool::factory()->create()->id);
    Ip::factory()->create(['address' => '172.16.30.20']);

    app(ImportCustomers::class)->import(importCustomerRows([
        'service_type' => 'ipoe', 'ip' => '172.16.30.20', 'password' => 'ignored', 'device_limit' => '5',
    ]));

    $account = Account::query()->sole();

    expect($account->password)->toHaveLength(16)->not->toBe('ignored')
        ->and($account->only(['mac_type', 'pool_id']))->toBe(['mac_type' => 1, 'pool_id' => null])
        ->and(Ipoe::query()->sole()->ip_address)->toBe('172.16.30.20')
        ->and(Ip::query()->where('address', '172.16.30.20')->value('is_used'))->toBeTrue();
});

it('checks the IP as the form does', function (Closure $arrange, array $values, string $line) {
    $arrange();

    expect(customerCheck($values))->toBe([$line]);
})->with([
    'IPoE without IP' => [fn () => null, ['service_type' => 'IPOE'], '2 ip: IPoE customers are found by their IP address. Enter one.'],
    'not in the inventory' => [fn () => null, ['ip' => '10.9.9.9'], '2 ip: This address is not in the IP inventory, or it was taken out. Add its range under Network, IP ranges.'],
    'held by another customer' => [
        fn () => Account::factory()->create(['username' => 'bob', 'ip_id' => Ip::factory()->create(['address' => '10.9.9.9', 'is_used' => true])->id]),
        ['ip' => '10.9.9.9'],
        '2 ip: bob already has this address.',
    ],
    'IPoE with a pool' => [
        fn () => [Ip::factory()->create(['address' => '10.9.9.9']), Pool::factory()->create(['name' => 'pppoe'])],
        ['service_type' => 'IPOE', 'ip' => '10.9.9.9', 'pool' => 'pppoe'],
        '2 pool: IPoE customers get no pool: their device keeps its own address.',
    ],
]);

it('gives a customer without a pool the default pool of their type, and finds a named pool in any case', function () {
    $default = Pool::factory()->create(['name' => 'pppoe']);
    $other = Pool::factory()->create(['name' => 'business']);
    AppSetting::put('default_pool_ppp', (string) $default->id);

    app(ImportCustomers::class)->import(importCustomerRows([], ['username' => 'bob', 'pool' => 'Business']));

    expect(Account::query()->orderBy('username')->pluck('pool_id')->all())->toBe([$default->id, $other->id])
        ->and(customerCheck(['username' => 'carol', 'pool' => 'nowhere']))->toBe(['2 pool: No pool named nowhere.']);
});

it('binds the MAC as the customer device, in any common form', function (string $mac) {
    app(ImportCustomers::class)->import(importCustomerRows(['mac' => $mac]));

    expect(DB::table('account_macs')->get(['username', 'mac_norm'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['username' => 'alice', 'mac_norm' => 'AABBCCDDEE01']]);
})->with(['AA:BB:CC:DD:EE:01', 'aa-bb-cc-dd-ee-01', 'AABB.CCDD.EE01', 'aabbccddee01']);

it('refuses a malformed MAC and one another customer uses', function () {
    DB::table('account_macs')->insert(['username' => 'bob', 'mac_norm' => 'AABBCCDDEE01', 'learned_at' => now()]);

    expect(customerCheck(['mac' => 'AA:BB'], ['username' => 'carol', 'mac' => 'aa:bb:cc:dd:ee:01']))->toBe([
        '2 mac: Enter a MAC address, e.g. AA:BB:CC:DD:EE:FF.',
        '3 mac: bob already uses this device.',
    ]);
});

it('refuses the same username, IP or MAC twice in one file, whatever the case', function () {
    Ip::factory()->create(['address' => '10.9.9.9']);

    expect(customerCheck(
        ['ip' => '10.9.9.9', 'mac' => 'AA:BB:CC:DD:EE:01'],
        ['username' => 'Alice'],
        ['username' => 'bob', 'ip' => '10.9.9.9'],
        ['username' => 'carol', 'mac' => 'aabbccddee01'],
    ))->toBe([
        '3 username: Row 2 already uses this username.',
        '4 ip: Row 2 already uses this IP address.',
        '5 mac: Row 2 already uses this MAC address.',
    ]);
});

it('refuses a username the app already has, whatever its case', function (bool $deleted, string $line) {
    $account = Account::factory()->create(['username' => 'alice']);

    if ($deleted) {
        $account->delete();
    }

    expect(customerCheck(['username' => 'Alice']))->toBe([$line]);
})->with([
    'live' => [false, '2 username: The username has already been taken.'],
    'deleted' => [true, '2 username: A deleted customer uses this username. Restore it instead.'],
]);

it('refuses a value Excel turned into a number, which would become a wrong login', function (string $column) {
    expect(customerCheck([$column => '1.23457E+11']))
        ->toBe(["2 {$column}: Excel turned this into a number. Format the column as Text in Excel and type it again."]);
})->with(['username', 'password', 'mobile']);

it('checks the profile and service fields as the form does', function (array $values, string $line) {
    expect(customerCheck($values))->toBe([$line]);
})->with([
    'no name' => [['fullname' => ''], '2 fullname: The fullname field is required.'],
    'mobile digits' => [['mobile' => '12345'], '2 mobile: Enter 10 digits.'],
    'email' => [['email' => 'not-an-email'], '2 email: The email field must be a valid email address.'],
    'username characters' => [['username' => 'al ice'], '2 username: Use letters, digits, ., -, _ and @ only.'],
    'password quote' => [['password' => 'pa"ss'], '2 password: Leave out quotes, backticks and backslashes: FreeRADIUS reads them as part of its own syntax.'],
    'no password' => [['password' => ''], '2 password: The password field is required.'],
    'service type' => [['service_type' => 'STATIC'], '2 service_type: Choose PPPoE or IPoE. Hotspot is turned off in App settings.'],
    'device limit' => [['device_limit' => '101'], '2 device_limit: The device limit field must be between 0 and 100.'],
    'address without line 1' => [['city' => 'Kolkata'], '2 line1: Enter the first address line, or leave the whole address empty.'],
    'postal code' => [['line1' => '12 Park Street', 'postal_code' => str_repeat('7', 21)], '2 postal_code: The postal code field must not be greater than 20 characters.'],
    'connection type' => [['connection_type' => 'Nowhere'], '2 connection_type: No connection type named Nowhere.'],
    'name Excel could not save' => [['fullname' => '??? ???'], '2 fullname: Excel turned letters it could not save into ?. Save the file as "CSV UTF-8" and check it again.'],
    'address Excel could not save' => [['line1' => '12 Park Street', 'city' => '?????'], '2 city: Excel turned letters it could not save into ?. Save the file as "CSV UTF-8" and check it again.'],
]);

it('saves the address and the connection type', function () {
    $home = ConnectionType::factory()->create(['name' => 'Home']);

    app(ImportCustomers::class)->import(importCustomerRows([
        'line1' => '12 Park Street', 'city' => 'Kolkata', 'country' => 'India', 'connection_type' => 'home', 'device_limit' => '2',
    ]));

    $account = Account::query()->with('address')->sole();

    expect($account->connection_type_id)->toBe($home->id)
        ->and($account->mac_type)->toBe(2)
        ->and($account->address->only(['line1', 'city', 'country', 'line2']))
        ->toBe(['line1' => '12 Park Street', 'city' => 'Kolkata', 'country' => 'India', 'line2' => null]);
});

it('imports the contact and billing columns', function () {
    turnInvoicingOn();

    app(ImportCustomers::class)->import(importCustomerRows([
        'company' => 'Example Traders', 'whatsapp' => '9876500001', 'alt_number' => '9876500002', 'tax_id' => '09abcde1234f1z5',
        'business_id' => 'ABCDE1234F', 'auto_renew' => 'Yes', 'free' => 'no', 'discount_percent' => '12.5',
        'payment_terms_days' => '7', 'billing_name' => 'Example Traders Pvt Ltd', 'place_of_supply' => 'uttar pradesh',
    ]));

    expect(Account::query()->sole()->only(['company', 'whatsapp', 'alt_number', 'tax_id', 'business_id', 'auto_renew', 'billing_free', 'discount_percent', 'payment_terms_days', 'billing_name', 'place_of_supply']))->toBe([
        'company' => 'Example Traders', 'whatsapp' => '9876500001', 'alt_number' => '9876500002', 'tax_id' => '09ABCDE1234F1Z5',
        'business_id' => 'ABCDE1234F', 'auto_renew' => true, 'billing_free' => false, 'discount_percent' => '12.50',
        'payment_terms_days' => 7, 'billing_name' => 'Example Traders Pvt Ltd', 'place_of_supply' => '09',
    ]);
});

it('gives blank billing columns the defaults the form uses', function () {
    turnInvoicingOn();
    AppSetting::put('billing.default_terms_days', '15');

    app(ImportCustomers::class)->import(importCustomerRows(['tax_id' => '09ABCDE1234F1Z5']));

    expect(Account::query()->sole()->only(['auto_renew', 'billing_free', 'discount_percent', 'payment_terms_days', 'billing_name', 'place_of_supply']))->toBe([
        'auto_renew' => false, 'billing_free' => false, 'discount_percent' => '0.00', 'payment_terms_days' => 15, 'billing_name' => null, 'place_of_supply' => '09',
    ]);
});

it('takes the place of supply by its code', function () {
    turnInvoicingOn();

    app(ImportCustomers::class)->import(importCustomerRows(['tax_id' => '09ABCDE1234F1Z5', 'place_of_supply' => '7']));

    expect(Account::query()->sole()->place_of_supply)->toBe('07');
});

it('checks the billing columns as the form does', function (array $values, string $line) {
    turnInvoicingOn();

    expect(customerCheck($values))->toBe([$line]);
})->with([
    'auto renew' => [['auto_renew' => 'maybe'], '2 auto_renew: Use yes or no.'],
    'free' => [['free' => '1x'], '2 free: Use yes or no.'],
    'discount' => [['discount_percent' => '101'], '2 discount_percent: The discount percent field must be between 0 and 100.'],
    'terms' => [['payment_terms_days' => '400'], '2 payment_terms_days: The payment terms days field must be between 0 and 365.'],
    'whatsapp digits' => [['whatsapp' => '12345'], '2 whatsapp: Enter 10 digits.'],
    'gstin' => [['tax_id' => '09ABCDE1234F1X5'], '2 tax_id: Enter a valid GSTIN, e.g. 09ABCDE1234F1Z5.'],
    'unknown state' => [['tax_id' => '09ABCDE1234F1Z5', 'place_of_supply' => 'Atlantis'], '2 place_of_supply: No state named Atlantis.'],
    'state without gstin' => [['place_of_supply' => 'Delhi'], '2 place_of_supply: A place of supply needs a GSTIN.'],
]);

it('refuses a place of supply without India GST', function () {
    expect(customerCheck(['tax_id' => 'GB123', 'place_of_supply' => 'Delhi']))
        ->toBe(['2 place_of_supply: A place of supply is only used with India GST.']);
});

it('refuses pricing and auto renew columns from staff who may not set them', function (Permission $missing, array $values, string $line) {
    turnInvoicingOn();
    $this->actingAs(staffLacking($missing));

    expect(customerCheck($values))->toBe([$line])
        ->and(customerCheck(['auto_renew' => 'no', 'free' => 'no', 'discount_percent' => '0']))->toBe([]);
})->with([
    'free' => [Permission::SetCustomerPricing, ['free' => 'yes'], '2 free: You may not set customer pricing.'],
    'discount' => [Permission::SetCustomerPricing, ['discount_percent' => '5'], '2 discount_percent: You may not set customer pricing.'],
    'place of supply' => [Permission::SetCustomerPricing, ['tax_id' => '09ABCDE1234F1Z5', 'place_of_supply' => 'Delhi'], '2 place_of_supply: You may not set customer pricing.'],
    'auto renew' => [Permission::RechargeCustomers, ['auto_renew' => 'yes'], '2 auto_renew: You may not recharge customers, so auto renew stays off.'],
]);

it('logs each customer as imported, with the address and never the password', function () {
    app(ImportCustomers::class)->import(importCustomerRows(['line1' => '12 Park Street', 'city' => 'Kolkata']));

    $entry = Activity::query()->where('subject_type', (new Account)->getMorphClass())->sole();

    expect($entry->event)->toBe('imported')
        ->and($entry->attribute_changes['attributes'])->toMatchArray(['username' => 'alice', 'address' => '12 Park Street, Kolkata'])
        ->and(json_encode($entry->attribute_changes))->not->toContain('secret12');
});

it('logs the device an imported customer is bound to', function () {
    app(ImportCustomers::class)->import(importCustomerRows(['mac' => 'aa-bb-cc-dd-ee-01']));

    expect(Activity::query()->where('subject_type', (new Account)->getMorphClass())->sole()->attribute_changes['attributes'])
        ->toMatchArray(['mac' => 'AA:BB:CC:DD:EE:01']);
});

it('writes nothing when one row is wrong', function () {
    Ip::factory()->create(['address' => '10.9.9.9']);

    expect(failedCustomerImport(importCustomerRows(
        ['ip' => '10.9.9.9', 'mac' => 'AA:BB:CC:DD:EE:01'],
        ['username' => 'bob', 'fullname' => ''],
    )))->toBe(['3 fullname: The fullname field is required.'])
        ->and(Account::query()->count())->toBe(0)
        ->and(DB::table('radcheck')->count())->toBe(0)
        ->and(DB::table('account_macs')->count())->toBe(0)
        ->and(Ip::query()->where('address', '10.9.9.9')->value('is_used'))->toBeFalse();
});

it('reports a username taken between the check and the import', function () {
    $rows = importCustomerRows([]);
    expect(app(ImportCustomers::class)->check($rows))->toBe([]);

    Account::factory()->create(['username' => 'alice']);

    expect(failedCustomerImport($rows))->toBe(['2 username: The username has already been taken.']);
});

it('turns a unique violation at the last moment into a file problem', function () {
    $taken = false;
    Account::creating(function (Account $account) use (&$taken): void {
        if (! $taken) {
            $taken = true;
            Account::factory()->make(['username' => $account->username, 'plan_id' => null, 'expiry' => null])->saveQuietly();
        }
    });

    expect(failedCustomerImport(importCustomerRows([])))
        ->toBe(['- -: A record in this file was taken meanwhile by someone else. Check the file again.'])
        ->and(Account::query()->count())->toBe(0);
});

it('refuses Hotspot rows while Hotspot is turned off', function () {
    expect(customerCheck(['service_type' => 'HOTSPOT']))
        ->toBe(['2 service_type: Choose PPPoE or IPoE. Hotspot is turned off in App settings.']);

    turnHotspotOn();

    expect(customerCheck(['service_type' => 'HOTSPOT']))->toBe([]);
});
