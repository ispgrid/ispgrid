<?php

use App\Actions\Import\RowError;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
