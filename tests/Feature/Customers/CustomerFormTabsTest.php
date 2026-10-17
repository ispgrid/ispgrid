<?php

use App\Models\Account;
use App\Models\AppSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\Addons\CustomerFormTabs;
use App\Support\Permissions;
use Database\Seeders\RadiusAttributeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission as PermissionRow;
use Tests\Fixtures\FixtureCustomerTab;

beforeEach(function () {
    $this->seed(RadiusAttributeSeeder::class);
    AppSetting::put('phone_digits', '10');
    View::addNamespace('fixtures', base_path('tests/Fixtures/views'));
    app(Permissions::class)->register('fixture-tab', fn (): string => 'Fixture tab', fn (): string => __('Customers'));
    PermissionRow::findOrCreate('fixture-tab', 'web');
    app(CustomerFormTabs::class)->register(FixtureCustomerTab::class);
    FixtureCustomerTab::$finished = [];
    $this->actingAs(User::factory()->create());
});

function tabbedCustomerForm(?int $account = null): Testable
{
    if ($account !== null) {
        return Livewire::test('pages::customers.form', ['account' => $account]);
    }

    return Livewire::test('pages::customers.form')
        ->set('fullname', 'Alice Rao')
        ->set('service_type', 'PPP')
        ->set('username', 'alice')
        ->set('password', 'secret12');
}

function deskWithoutFixtureTab(): User
{
    Role::create(['name' => 'Desk', 'guard_name' => 'web'])->givePermissionTo(['view-customers', 'add-customers']);

    return User::factory()->role('Desk')->create();
}

it('shows an add-on tab after the core tabs to staff it allows', function () {
    tabbedCustomerForm()
        ->assertSeeInOrder([__('Profile'), __('Service'), __('Network'), __('Attributes'), 'Fixture tab'])
        ->assertSee('Fixture tab panel');
});

it('hides an add-on tab from staff it does not allow', function () {
    $this->actingAs(deskWithoutFixtureTab());

    tabbedCustomerForm()->assertDontSee('Fixture tab');
});

it('fills the tab from the customer when editing', function () {
    $account = Account::factory()->create();
    AppSetting::put("fixture.note.{$account->id}", 'Kept note');

    tabbedCustomerForm($account->id)->assertSet('addonTabs.fixture.note', 'Kept note');
});

it('marks the add-on tab when its field is invalid, and saves nothing', function () {
    $form = tabbedCustomerForm()->set('addonTabs.fixture.note', str_repeat('x', 21))->call('save')
        ->assertHasErrors(['addonTabs.fixture.note']);

    expect($form->instance()->invalidTabs())->toBe(['fixture'])
        ->and(Account::query()->count())->toBe(0);
});

it('marks the tab amber while it has a warning, and names the warning after saving', function () {
    $form = tabbedCustomerForm();

    expect($form->instance()->warningTabs())->toBe(['fixture']);

    $form->call('save')->assertHasNoErrors();

    expect(session('toast'))->toBe('Customer saved. Note is empty.');
});

it('saves the tab with the customer, then runs its after-save step', function () {
    tabbedCustomerForm()->set('addonTabs.fixture.note', 'Hello')->call('save')->assertHasNoErrors();

    $account = Account::query()->sole();

    expect(AppSetting::valueOf("fixture.note.{$account->id}"))->toBe('Hello')
        ->and(FixtureCustomerTab::$finished)->toHaveCount(1)
        ->and(FixtureCustomerTab::$finished[0]['state']['note'])->toBe('Hello')
        ->and(session('toast'))->toBe('Customer saved.');
});

it('rolls the whole customer back when a tab fails to save', function () {
    tabbedCustomerForm()->set('addonTabs.fixture.note', 'fail-save')->call('save')
        ->assertHasErrors(['addonTabs.fixture']);

    expect(Account::query()->count())->toBe(0)
        ->and(DB::table('radcheck')->where('username', 'alice')->count())->toBe(0)
        ->and(FixtureCustomerTab::$finished)->toBe([]);
});

it('keeps the customer when the after-save step fails, and says so', function () {
    tabbedCustomerForm()->set('addonTabs.fixture.note', 'fail-saved')->call('save')->assertHasNoErrors();

    expect(Account::query()->count())->toBe(1)
        ->and(session('toast'))->toContain('Fixture tab could not finish');
});

it('ignores state sent for a tab the staff member cannot see', function () {
    $this->actingAs(deskWithoutFixtureTab());

    tabbedCustomerForm()->set('addonTabs', ['fixture' => ['note' => 'Sneaky']])->call('save')->assertHasNoErrors();

    $account = Account::query()->sole();

    expect(AppSetting::valueOf("fixture.note.{$account->id}"))->toBeNull()
        ->and(FixtureCustomerTab::$finished)->toBe([]);
});

it('refuses a second tab with the same key', function () {
    app(CustomerFormTabs::class)->register(FixtureCustomerTab::class);
})->throws(InvalidArgumentException::class);

it('takes a file upload in an add-on tab and hands it over after the save', function () {
    tabbedCustomerForm()
        ->set('addonTabs.fixture.note', 'With a file')
        ->set('addonTabs.fixture.file', UploadedFile::fake()->create('id-proof.pdf', 100, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $file = FixtureCustomerTab::$finished[0]['state']['file'];

    expect($file)->toBeInstanceOf(TemporaryUploadedFile::class)
        ->and($file->getClientOriginalName())->toBe('id-proof.pdf');
});

it('refuses an add-on upload over the tab\'s own limit', function () {
    tabbedCustomerForm()
        ->set('addonTabs.fixture.file', UploadedFile::fake()->create('scan.pdf', 3000, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['addonTabs.fixture.file']);

    expect(Account::query()->count())->toBe(0);
});
