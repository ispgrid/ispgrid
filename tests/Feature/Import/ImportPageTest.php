<?php

use App\Actions\Import\ImportCustomers;
use App\Actions\Import\ImportTime;
use App\Models\AppSetting;
use App\Models\ConnectionType;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    AppSetting::put('phone_digits', '10');
    $this->actingAs(User::factory()->create());
});

function planCsv(string ...$rows): UploadedFile
{
    return UploadedFile::fake()->createWithContent('plans.csv', "slug,name,validity_days,price,upload,download\n".implode("\n", $rows)."\n");
}

it('opens the import screen', function () {
    $this->get(route('import.index'))->assertOk()->assertSee('Download template')->assertSee('Import plans first');
});

it('offers a template for each file', function () {
    Livewire::test('pages::system.import')
        ->call('downloadTemplate', 'plans')
        ->assertFileDownloaded('plans-template.csv', "slug,name,validity_days,price,upload,download,pool,description\nhome_25,\"Home 25\",30,599,25M,25M,,\"Home plan, 25 Mbps\"\n")
        ->call('downloadTemplate', 'customers')
        ->assertFileDownloaded('customers-template.csv');
});

it('accepts its own customers template once the plan and connection type exist', function () {
    Plan::factory()->create(['slug' => 'home_25']);
    ConnectionType::factory()->create(['name' => 'Home']);
    $example = app(ImportCustomers::class)->example();
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, array_keys($example), escape: '');
    fputcsv($stream, array_values($example), escape: '');
    rewind($stream);

    Livewire::test('pages::system.import')
        ->set('customersFile', UploadedFile::fake()->createWithContent('customers.csv', stream_get_contents($stream)))
        ->call('check', 'customers')
        ->assertSet('ready.customers', 1)
        ->assertSet('problems.customers', []);
});

it('imports a file after a clean check', function () {
    Livewire::test('pages::system.import')
        ->set('plansFile', planCsv('home_25,Home 25,30,599,25M,25M', 'office,Office,30,999,50M,50M'))
        ->call('check', 'plans')
        ->assertSet('ready.plans', 2)
        ->assertSee('2 rows are ready to import.')
        ->call('import', 'plans')
        ->assertSet('ready.plans', null)
        ->assertSet('plansFile', null)
        ->assertDispatched('toast', message: '2 plans imported.');

    expect(Plan::query()->count())->toBe(2);
});

it('lists every problem with its row and column, and imports nothing', function () {
    Livewire::test('pages::system.import')
        ->set('plansFile', planCsv('Home 25,Home 25,30,599,25M,25M', 'office,,30,999,50M,50M'))
        ->call('check', 'plans')
        ->assertSet('ready.plans', null)
        ->assertSee('Nothing was saved. Fix 2 problems and check the file again.')
        ->assertSee('Use lower-case letters, digits, - and _ only.')
        ->assertSee('The name field is required.')
        ->call('import', 'plans')
        ->assertDispatched('toast', message: 'Check the file first.', variant: 'danger');

    expect(Plan::query()->count())->toBe(0);
});

it('shows problems with the file itself', function () {
    Livewire::test('pages::system.import')
        ->set('plansFile', UploadedFile::fake()->createWithContent('plans.csv', "slug,speed\nhome,1\n"))
        ->call('check', 'plans')
        ->assertSee('Unknown column: speed.');
});

it('lists the problems of the other rows next to a row with a stray comma', function () {
    Livewire::test('pages::system.import')
        ->set('plansFile', planCsv('home_25,Home 25,30,599,25M,25M,extra', 'Office,Office,30,999,50M,50M'))
        ->call('check', 'plans')
        ->assertSet('problems.plans', [
            ['row' => 2, 'column' => null, 'message' => 'This row has 7 cells but the header has 6. Check for a stray comma.'],
            ['row' => 3, 'column' => 'slug', 'message' => 'Use lower-case letters, digits, - and _ only.'],
        ]);
});

it('hides Import and Check while a new file uploads, so the old file is not used', function () {
    Livewire::test('pages::system.import')
        ->set('plansFile', planCsv('home_25,Home 25,30,599,25M,25M'))
        ->call('check', 'plans')
        ->assertSeeHtml('wire:loading.remove wire:target="plansFile"')
        ->assertSeeHtml('wire:loading.attr="disabled" wire:target="plansFile"');
});

it('lets the file picker show .txt files, which are accepted too', function () {
    $this->get(route('import.index'))->assertSee('accept=".csv,.txt,text/csv,text/plain"', false);
});

it('does not let the browser mark a file as checked', function () {
    expect(fn () => Livewire::test('pages::system.import')->set('ready.plans', 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('only offers what the role may also add through the forms', function () {
    Role::create(['name' => 'Plan desk', 'guard_name' => 'web'])->givePermissionTo(['import-data', 'view-plans', 'manage-plans']);
    $this->actingAs(User::factory()->role('Plan desk')->create());

    Livewire::test('pages::system.import')
        ->assertSee('One plan per row')->assertDontSee('Import plans first')
        ->set('plansFile', planCsv('home_25,Home 25,30,599,25M,25M'))
        ->call('check', 'plans')->assertSet('ready.plans', 1)
        ->call('import', 'plans')->assertSet('ready.plans', null);

    expect(Plan::query()->where('slug', 'home_25')->exists())->toBeTrue();

    Livewire::test('pages::system.import')->call('check', 'customers')->assertForbidden();
    Livewire::test('pages::system.import')->call('import', 'customers')->assertForbidden();
    Livewire::test('pages::system.import')->call('downloadTemplate', 'customers')->assertForbidden();
});

it('says so when the role may import but add nothing', function () {
    Role::create(['name' => 'Importer only', 'guard_name' => 'web'])->givePermissionTo(['import-data']);
    $this->actingAs(User::factory()->role('Importer only')->create());

    Livewire::test('pages::system.import')
        ->assertDontSee('Download template')
        ->assertSee('Your role may import, but not add plans or customers, so there is nothing to import.');
});

it('forgets the last check when another file is chosen', function () {
    Livewire::test('pages::system.import')
        ->set('plansFile', planCsv('home_25,Home 25,30,599,25M,25M'))
        ->call('check', 'plans')
        ->assertSet('ready.plans', 1)
        ->set('plansFile', planCsv('office,Office,30,999,50M,50M'))
        ->assertSet('ready.plans', null);
});

it('asks for a check again after an import, so nothing is imported twice', function () {
    Livewire::test('pages::system.import')
        ->set('plansFile', planCsv('home_25,Home 25,30,599,25M,25M'))
        ->call('check', 'plans')
        ->call('import', 'plans')
        ->call('import', 'plans')
        ->assertDispatched('toast', message: 'Check the file first.', variant: 'danger');

    expect(Plan::query()->count())->toBe(1);
});

it('shows the problems found at import time', function () {
    $page = Livewire::test('pages::system.import')
        ->set('plansFile', planCsv('home_25,Home 25,30,599,25M,25M'))
        ->call('check', 'plans')
        ->assertSet('ready.plans', 1);

    Plan::factory()->create(['slug' => 'home_25']);

    $page->call('import', 'plans')
        ->assertSet('ready.plans', null)
        ->assertSee('The slug has already been taken.');
});

it('refuses a file that is not CSV or larger than 2 MB', function (UploadedFile $file) {
    Livewire::test('pages::system.import')
        ->set('plansFile', $file)
        ->call('check', 'plans')
        ->assertHasErrors(['plansFile']);
})->with([
    'pdf' => fn () => UploadedFile::fake()->create('plans.pdf', 10),
    'too large' => fn () => UploadedFile::fake()->create('plans.csv', 3000),
]);

it('tells how to stop Excel changing usernames, passwords and dates', function () {
    $this->get(route('import.index'))->assertSee('In Excel, format the username, password, mobile and expiry columns as Text before typing.');
});

it('gives a large file the time it needs to check and import', function (string $action) {
    ini_set('max_execution_time', '30');

    $page = Livewire::test('pages::system.import')->set('plansFile', planCsv('home_25,Home 25,30,599,25M,25M'));
    $action === 'import' ? $page->call('check', 'plans')->tap(fn () => ini_set('max_execution_time', '30'))->call('import', 'plans') : $page->call('check', 'plans');

    $limit = ini_get('max_execution_time');
    ini_set('max_execution_time', '0');

    expect($limit)->toBe((string) ImportTime::SECONDS);
})->with(['check', 'import']);

it('asks for a file before checking', function () {
    Livewire::test('pages::system.import')->call('check', 'plans')->assertHasErrors(['plansFile' => 'required']);
});

it('knows only plans and customers', function () {
    Livewire::test('pages::system.import')->call('check', 'routers')->assertStatus(404);
});
