<?php

use App\Actions\Import\RowError;
use App\Billing\LineInput;
use App\Enums\Permission;
use App\Models\AppSetting;
use App\Models\GatewaySetting;
use App\Models\RadiusSetting;
use App\Models\Role;
use App\Models\User;
use App\Payments\PaymentGateways;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\FakeGateway;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Retry tests need the app's own transaction to be the outer one: inside RefreshDatabase's wrapping
| transaction, Laravel rethrows a deadlock instead of retrying. They empty the tables before and after
| each test instead, so nothing they write reaches another test.
*/
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->afterEach(function () {
        $this->truncateDatabaseTables();
    })
    ->in('Retry');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
/**
 * Import problems as "row column: message" lines ("-" for a whole file or a whole row), for readable expectations.
 *
 * @param  list<RowError>  $errors
 * @return list<string>
 */
function errorLines(array $errors): array
{
    return array_map(
        fn (RowError $error): string => ($error->row ?? '-').' '.($error->column ?? '-').': '.$error->message,
        $errors,
    );
}

/**
 * A staff member whose role holds exactly these boxes.
 */
function staffHolding(Permission ...$permissions): User
{
    $role = Role::create(['name' => 'Role '.Str::random(8)]);
    $role->syncPermissions(array_map(fn (Permission $permission): string => $permission->value, $permissions));

    return User::factory()->role($role->name)->create();
}

/**
 * A staff member whose role holds every box except this one.
 */
function staffLacking(Permission $missing): User
{
    return staffHolding(...array_filter(Permission::cases(), fn (Permission $permission): bool => $permission !== $missing));
}

/**
 * Turns the Hotspot switch on (the FreeRADIUS settings row, created when a test has none).
 */
function turnHotspotOn(): void
{
    RadiusSetting::query()->updateOrCreate(['id' => 1], ['hotspot_enabled' => true]);
}

/**
 * A day of usage in the usage database, as the radacct triggers would book it.
 */
function usageDay(string $username, string $day, int $download, int $upload = 0, int $seconds = 0): void
{
    DB::table(config('ispgrid.usage_database').'.usage_daily')->insert(['username' => $username, 'day' => $day, 'bytes_in' => $upload, 'bytes_out' => $download, 'seconds' => $seconds]);
}

/**
 * The customer's usage since their last recharge, as the usage database holds it.
 */
function usagePeriod(string $username, int $bytes, int $seconds = 0, ?string $since = null): void
{
    DB::table(config('ispgrid.usage_database').'.usage_period')->upsert([['username' => $username, 'since' => $since, 'bytes' => $bytes, 'seconds' => $seconds]], ['username']);
}

/**
 * Turns invoicing on for "Example ISP": by default in India (Uttar Pradesh, 09) with GST at 18%.
 *
 * @param  array<string, string>  $tax
 */
function turnInvoicingOn(string $country = 'IN', string $taxSetup = 'india-gst', array $tax = ['rate' => '18', 'state' => '09']): void
{
    AppSetting::put('billing.enabled', '1');
    AppSetting::put('billing.company', (string) json_encode(['name' => 'Example ISP', 'country' => $country, 'address_lines' => ['1 Example Street'], 'city' => 'Prayagraj', 'tax_number' => '09ABCDE1234F1Z5']));
    AppSetting::put('billing.tax_setup', $taxSetup);
    AppSetting::put('billing.tax', (string) json_encode($tax));
}

/**
 * Registers the test payment gateway (Tests\Fakes\FakeGateway) and turns it on with a known API key.
 */
function useFakeGateway(bool $enabled = true): void
{
    FakeGateway::reset();
    app(PaymentGateways::class)->register(FakeGateway::class);
    GatewaySetting::query()->updateOrCreate(['gateway' => 'fake'], ['enabled' => $enabled, 'settings' => ['account' => 'ACC1', 'api_key' => 'test-secret']]);
}

/**
 * Invoicing, the test gateway and quick pay all on.
 */
function turnQuickPayOn(?string $renewalDays = '7'): void
{
    turnInvoicingOn();
    useFakeGateway();
    AppSetting::put('billing.quick_pay', '1');
    AppSetting::put('billing.quick_pay_renewal_days', $renewalDays ?? '');
}

/**
 * One taxed invoice line at this price.
 *
 * @return list<LineInput>
 */
function billingLines(string $price, string $quantity = '1', string $discount = '0'): array
{
    return [new LineInput('Plan', null, null, $quantity, $price, $discount, true)];
}
