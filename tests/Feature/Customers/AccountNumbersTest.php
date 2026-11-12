<?php

use App\Actions\Billing\IssueInvoice;
use App\Actions\Import\ImportCustomers;
use App\Billing\Pdf\InvoiceView;
use App\Enums\InvoiceKind;
use App\Jobs\NumberAccounts;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\AccountNumbers;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RadiusAttributeSeeder;
use Database\Seeders\RadiusSettingSeeder;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(AppSettingSeeder::class);
    $this->seed(RadiusSettingSeeder::class);
    $this->seed(RadiusAttributeSeeder::class);
    AppSetting::put('phone_digits', '10');
    $this->actingAs(User::factory()->create());
});

function accountIdsOn(string $prefix = 'LINK-', string $start = '1000001'): void
{
    AppSetting::put(AccountNumbers::PREFIX, $prefix);
    AppSetting::put(AccountNumbers::START, $start);
    AppSetting::put(AccountNumbers::NEXT, $start);
}

function numberedCustomerForm(string $username): Testable
{
    return Livewire::test('pages::customers.form')
        ->set('fullname', 'Customer '.$username)
        ->set('service_type', 'PPP')
        ->set('username', $username)
        ->set('password', 'secret12');
}

function accountNumberOf(string $username): ?string
{
    return Account::query()->where('username', $username)->value('account_number');
}

it('gives new customers no ID while the start number is empty', function () {
    numberedCustomerForm('alice')->call('save')->assertHasNoErrors();

    expect(accountNumberOf('alice'))->toBeNull();
});

it('numbers new customers from the start number with the prefix', function () {
    accountIdsOn();

    numberedCustomerForm('alice')->call('save')->assertHasNoErrors();
    numberedCustomerForm('bob')->call('save')->assertHasNoErrors();

    expect(accountNumberOf('alice'))->toBe('LINK-1000001')
        ->and(accountNumberOf('bob'))->toBe('LINK-1000002');
});

it('takes an ID staff type for a new customer', function () {
    accountIdsOn();

    numberedCustomerForm('alice')->set('account_number', 'OLD-77')->call('save')->assertHasNoErrors();

    expect(accountNumberOf('alice'))->toBe('OLD-77');
});

it('skips an ID staff typed by hand', function () {
    accountIdsOn();
    Account::factory()->create(['username' => 'old', 'account_number' => 'LINK-1000001']);

    numberedCustomerForm('alice')->call('save')->assertHasNoErrors();

    expect(accountNumberOf('alice'))->toBe('LINK-1000002');
});

it('keeps old IDs when the prefix changes', function () {
    accountIdsOn();
    numberedCustomerForm('alice')->call('save')->assertHasNoErrors();
    AppSetting::put(AccountNumbers::PREFIX, 'NET');

    numberedCustomerForm('bob')->call('save')->assertHasNoErrors();

    expect(accountNumberOf('alice'))->toBe('LINK-1000001')
        ->and(accountNumberOf('bob'))->toBe('NET1000002');
});

it('lets staff change a customer ID and refuses one another customer has', function () {
    accountIdsOn();
    Account::factory()->create(['username' => 'old', 'account_number' => 'LINK-5']);
    $account = Account::factory()->create(['username' => 'alice', 'password' => 'secret12', 'account_number' => 'LINK-6']);

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->assertSet('account_number', 'LINK-6')
        ->set('account_number', 'link-5')
        ->call('save')
        ->assertHasErrors(['account_number' => 'unique']);

    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('account_number', 'LINK-9')
        ->call('save')
        ->assertHasNoErrors();

    expect(accountNumberOf('alice'))->toBe('LINK-9');
});

it('refuses characters an ID cannot hold', function () {
    accountIdsOn();

    numberedCustomerForm('alice')->set('account_number', 'A B')->call('save')->assertHasErrors(['account_number']);
});

it('numbers existing customers oldest first when the start number is first saved', function () {
    $first = Account::factory()->create();
    $second = Account::factory()->create();
    $deleted = Account::factory()->create();
    $deleted->delete();
    $kept = Account::factory()->create(['account_number' => 'KEEP-1']);

    Livewire::test('pages::system.app-settings')
        ->set('account_id_prefix', 'LINK-')
        ->set('account_id_start', '100')
        ->call('save')
        ->assertHasNoErrors();

    expect($first->fresh()->account_number)->toBe('LINK-100')
        ->and($second->fresh()->account_number)->toBe('LINK-101')
        ->and($deleted->fresh()->account_number)->toBeNull()
        ->and($kept->fresh()->account_number)->toBe('KEEP-1')
        ->and(Activity::query()->where('event', 'account-numbers-assigned')->sole()->attribute_changes['attributes']['count'])->toBe(2);
});

it('starts again from a new start number without numbering customers twice', function () {
    accountIdsOn('LINK-', '100');
    $account = Account::factory()->create(['account_number' => 'LINK-100']);

    Livewire::test('pages::system.app-settings')
        ->set('account_id_start', '500')
        ->call('save')
        ->assertHasNoErrors();
    numberedCustomerForm('alice')->call('save')->assertHasNoErrors();

    expect(accountNumberOf('alice'))->toBe('LINK-500')
        ->and(Activity::query()->where('event', 'account-numbers-assigned')->count())->toBe(0)
        ->and($account->fresh()->account_number)->toBe('LINK-100');
});

it('refuses a bad prefix or start number', function (string $field, string $value) {
    Livewire::test('pages::system.app-settings')
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field]);
})->with([
    ['account_id_prefix', 'A B'],
    ['account_id_prefix', 'ABCDEFGHIJK'],
    ['account_id_start', '0'],
    ['account_id_start', 'abc'],
]);

it('finds a customer by ID in the list with or without the prefix', function () {
    accountIdsOn();
    Account::factory()->create(['fullname' => 'Alice Rao', 'account_number' => 'LINK-1000001']);
    Account::factory()->create(['fullname' => 'Bob Das', 'account_number' => 'LINK-1000002']);

    Livewire::test('pages::customers.index')->set('search', 'LINK-1000001')
        ->assertSee('Alice Rao')->assertDontSee('Bob Das');
    Livewire::test('pages::customers.index')->set('search', '1000002')
        ->assertSee('Bob Das')->assertDontSee('Alice Rao')->assertSee('LINK-1000002');
});

it('finds the customer of a typed ID with or without the prefix', function () {
    accountIdsOn();
    $account = Account::factory()->create(['account_number' => 'LINK-1000001']);

    expect(AccountNumbers::matching('LINK-1000001')->value('id'))->toBe($account->id)
        ->and(AccountNumbers::matching('1000001')->value('id'))->toBe($account->id)
        ->and(AccountNumbers::matching('100000')->value('id'))->toBeNull();
});

it('shows the ID on the customer page', function () {
    $account = Account::factory()->create(['account_number' => 'LINK-1000001']);

    Livewire::test('pages::customers.show', ['account' => $account->id])->assertSee('Account ID')->assertSee('LINK-1000001');
});

it('prints the account ID on new invoices', function () {
    turnInvoicingOn();
    $account = Account::factory()->create(['username' => 'alice', 'account_number' => 'LINK-1000001']);

    $invoice = app(IssueInvoice::class)->handle($account, billingLines('500'), false, InvoiceKind::Manual, now());
    $view = InvoiceView::for($invoice);
    $html = view('pdf.invoice', ['invoice' => $view])->render();

    expect($view['customer']['account_number'])->toBe('LINK-1000001')
        ->and($html)->toContain('Account ID')->toContain('LINK-1000001');
});

it('prints no account ID row for a customer without one', function () {
    turnInvoicingOn();
    $account = Account::factory()->create(['username' => 'alice']);

    $invoice = app(IssueInvoice::class)->handle($account, billingLines('500'), false, InvoiceKind::Manual, now());
    $html = view('pdf.invoice', ['invoice' => InvoiceView::for($invoice)])->render();

    expect($html)->not->toContain('Account ID');
});

it('imports the account_id column and numbers rows without one', function () {
    accountIdsOn();
    $blank = array_fill_keys(app(ImportCustomers::class)->columns(), '');
    $row = fn (int $line, array $values): array => ['line' => $line, 'values' => [...$blank, 'service_type' => 'PPP', 'password' => 'secret12', ...$values]];

    app(ImportCustomers::class)->import([
        $row(2, ['username' => 'alice', 'fullname' => 'Alice', 'account_id' => 'OLD-1']),
        $row(3, ['username' => 'bob', 'fullname' => 'Bob', 'account_id' => '']),
    ]);

    expect(accountNumberOf('alice'))->toBe('OLD-1')
        ->and(accountNumberOf('bob'))->toBe('LINK-1000001');
});

it('refuses an imported account_id that is taken or repeated', function () {
    Account::factory()->create(['account_number' => 'OLD-1']);
    $blank = array_fill_keys(app(ImportCustomers::class)->columns(), '');
    $row = fn (int $line, array $values): array => ['line' => $line, 'values' => [...$blank, 'service_type' => 'PPP', 'password' => 'secret12', ...$values]];

    $errors = errorLines(app(ImportCustomers::class)->check([
        $row(2, ['username' => 'alice', 'fullname' => 'Alice', 'account_id' => 'OLD-1']),
        $row(3, ['username' => 'bob', 'fullname' => 'Bob', 'account_id' => 'NEW-1']),
        $row(4, ['username' => 'carol', 'fullname' => 'Carol', 'account_id' => 'new-1']),
    ]));

    expect($errors)->toHaveCount(2)
        ->and($errors[0])->toStartWith('2 account_id:')
        ->and($errors[1])->toBe('4 account_id: Row 3 already uses this account ID.');
});

it('shows the account ID on the Selfcare dashboard', function () {
    turnSelfcareOn();
    $account = Account::factory()->create(['account_number' => 'LINK-1000001', 'selfcare_password' => Hash::make('Selfcare-pass-1')]);
    actingAsCustomer($account);

    $this->get(route('selfcare.dashboard'))->assertOk()->assertSee('Account ID: LINK-1000001');
});

it('skips an ID typed further down the same import file', function () {
    accountIdsOn('LINK-', '1001');
    $blank = array_fill_keys(app(ImportCustomers::class)->columns(), '');
    $row = fn (int $line, array $values): array => ['line' => $line, 'values' => [...$blank, 'service_type' => 'PPP', 'password' => 'secret12', ...$values]];

    app(ImportCustomers::class)->import([
        $row(2, ['username' => 'alice', 'fullname' => 'Alice', 'account_id' => '']),
        $row(3, ['username' => 'bob', 'fullname' => 'Bob', 'account_id' => 'link-1001']),
    ]);

    expect(accountNumberOf('alice'))->toBe('LINK-1002')
        ->and(accountNumberOf('bob'))->toBe('link-1001');
});

it('keeps an ID given while an edit form with the field hidden was open', function () {
    $account = Account::factory()->create(['username' => 'alice', 'password' => 'secret12']);
    $form = Livewire::test('pages::customers.form', ['account' => $account->id])->assertSet('showsAccountNumber', false);
    $account->forceFill(['account_number' => 'LINK-7'])->save();

    $form->set('fullname', 'Alice Rao')->call('save')->assertHasNoErrors();

    expect(accountNumberOf('alice'))->toBe('LINK-7');
});

it('gives the numbering job time for a large customer base', function () {
    expect((new NumberAccounts)->timeout)->toBeGreaterThanOrEqual(1800);
});
