<?php

use App\Actions\Import\ImportPlans;
use App\Exceptions\ImportFailed;
use App\Models\Plan;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * Plan rows as CsvFile gives them (every column present), numbered from spreadsheet row 2. Each argument
 * overrides a valid base row.
 *
 * @param  array<string, string>  ...$rows
 * @return list<array{line: int, values: array<string, string>}>
 */
function importPlanRows(array ...$rows): array
{
    $blank = array_fill_keys(app(ImportPlans::class)->columns(), '');
    $base = ['slug' => 'home_25', 'name' => 'Home 25', 'validity_days' => '30', 'price' => '599', 'upload' => '25M', 'download' => '25M'];

    return array_map(
        fn (array $values, int $index): array => ['line' => $index + 2, 'values' => [...$blank, ...$base, ...$values]],
        $rows,
        array_keys($rows),
    );
}

/**
 * @param  list<array{line: int, values: array<string, string>}>  $rows
 * @return list<string>
 */
function failedPlanImport(array $rows): array
{
    try {
        app(ImportPlans::class)->import($rows);
    } catch (ImportFailed $failed) {
        return errorLines($failed->errors);
    }

    throw new RuntimeException('The import did not fail.');
}

it('imports plans and writes them to FreeRADIUS as the plan form does', function () {
    $pool = Pool::factory()->create(['name' => 'pppoe']);

    $count = app(ImportPlans::class)->import(importPlanRows(
        [],
        ['slug' => 'office', 'name' => 'Office', 'pool' => 'PPPOE', 'description' => 'For offices'],
    ));

    $office = Plan::query()->where('slug', 'office')->sole();

    expect($count)->toBe(2)
        ->and(Plan::query()->orderBy('slug')->pluck('slug')->all())->toBe(['home_25', 'office'])
        ->and($office->only(['name', 'validity', 'price', 'upload', 'download', 'pool_id', 'description']))->toEqual([
            'name' => 'Office', 'validity' => 30, 'price' => '599.00', 'upload' => '25M', 'download' => '25M',
            'pool_id' => $pool->id, 'description' => 'For offices',
        ])
        ->and(DB::table('radgroupreply')->where('groupname', 'office')->pluck('value', 'attribute')->all())
        ->toEqual(['Mikrotik-Rate-Limit' => '25M/25M', 'Framed-Pool' => 'pppoe']);
});

it('logs each plan as imported, not as created', function () {
    app(ImportPlans::class)->import(importPlanRows([]));

    // The staff user made in beforeEach has its own "created" entry; only the plan's entries matter here.
    $entry = Activity::query()->where('subject_type', (new Plan)->getMorphClass())->sole();

    expect($entry->event)->toBe('imported')
        ->and($entry->attribute_changes['attributes'])->toMatchArray(['slug' => 'home_25', 'name' => 'Home 25', 'upload' => '25M']);
});

it('checks a valid file without writing anything', function () {
    expect(app(ImportPlans::class)->check(importPlanRows([], ['slug' => 'office'])))->toBe([])
        ->and(Plan::query()->count())->toBe(0);
});

it('refuses what the plan form refuses', function (array $values, string $line) {
    expect(errorLines(app(ImportPlans::class)->check(importPlanRows($values))))->toBe([$line]);
})->with([
    'slug format' => [['slug' => 'Home 25'], '2 slug: Use lower-case letters, digits, - and _ only.'],
    'no name' => [['name' => ''], '2 name: The name field is required.'],
    'validity' => [['validity_days' => '0'], '2 validity_days: The validity field must be between 1 and 3650.'],
    'price' => [['price' => '5.999'], '2 price: The price field must have 0-2 decimal places.'],
    'speed format' => [['upload' => '25 Mbps'], '2 upload: Enter a whole number with k or M, e.g. 512k or 25M.'],
    'no download' => [['download' => ''], '2 download: The download speed field is required.'],
    'unknown pool' => [['pool' => 'nowhere'], '2 pool: No pool named nowhere.'],
]);

it('refuses a slug in use, also by a deleted plan', function () {
    Plan::factory()->create(['slug' => 'home_25']);
    Plan::factory()->create(['slug' => 'office'])->delete();

    expect(errorLines(app(ImportPlans::class)->check(importPlanRows([], ['slug' => 'office']))))->toBe([
        '2 slug: The slug has already been taken.',
        '3 slug: A deleted plan uses this slug. Restore it instead.',
    ]);
});

it('refuses the same slug twice in one file', function () {
    expect(errorLines(app(ImportPlans::class)->check(importPlanRows([], ['name' => 'Again']))))
        ->toBe(['3 slug: Row 2 already uses this slug.']);
});

it('writes nothing when one row is wrong', function () {
    expect(failedPlanImport(importPlanRows([], ['slug' => 'office', 'price' => ''])))->toBe(['3 price: The price field is required.'])
        ->and(Plan::query()->count())->toBe(0)
        ->and(DB::table('radgroupreply')->count())->toBe(0);
});

it('reports a slug taken between the check and the import', function () {
    $rows = importPlanRows([]);
    expect(app(ImportPlans::class)->check($rows))->toBe([]);

    Plan::factory()->create(['slug' => 'home_25']);

    expect(failedPlanImport($rows))->toBe(['2 slug: The slug has already been taken.'])
        ->and(Plan::query()->count())->toBe(1);
});

it('turns a unique violation at the last moment into a file problem', function () {
    $taken = false;
    Plan::creating(function (Plan $plan) use (&$taken): void {
        if (! $taken) {
            $taken = true;
            Plan::factory()->make(['slug' => $plan->slug])->saveQuietly();
        }
    });

    expect(failedPlanImport(importPlanRows([])))
        ->toBe(['- -: A record in this file was taken meanwhile by someone else. Check the file again.'])
        ->and(Plan::query()->count())->toBe(0);
});
