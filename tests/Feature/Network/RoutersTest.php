<?php

use App\Models\Account;
use App\Models\Nas;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const RESTART = 'sudo systemctl restart freeradius';

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config(['radius.restart_command' => RESTART]);
    Process::fake();
});

function addRouter(array $fields = []): Testable
{
    $fields += ['shortname' => 'core-1', 'nasname' => '10.0.0.1', 'secret' => 'Secret12345', 'routeros_version' => '7.23.7'];

    $page = Livewire::test('pages::network.routers')->call('create');

    foreach ($fields as $field => $value) {
        $page->set($field, $value);
    }

    return $page->call('save');
}

it('adds a router, marks it from its RouterOS version and restarts FreeRADIUS', function () {
    addRouter(['nasname' => ' 10.0.0.1 '])->assertHasNoErrors()->assertDispatched('toast', variant: 'success');

    $router = Nas::query()->sole();

    expect($router->nasname)->toBe('10.0.0.1')
        ->and($router->type)->toBe('mikrotik')
        ->and($router->require_message_auth)->toBeTrue();

    Process::assertRan(RESTART);
});

it('warns about an old RouterOS and leaves Message-Authenticator off for it', function () {
    addRouter(['routeros_version' => '6.49.17'])->assertHasNoErrors();

    expect(Nas::query()->sole()->require_message_auth)->toBeFalse();

    Livewire::test('pages::network.routers')
        ->assertSee('RouterOS 6.49.17 is affected by the Blast-RADIUS security issue (CVE-2024-3596)');
});

it('shows the warning in the form while the version is typed', function () {
    Livewire::test('pages::network.routers')
        ->call('create')
        ->set('routeros_version', '7.14.3')
        ->assertSee('RouterOS 7.14.3 is affected by the Blast-RADIUS security issue');
});

it('refuses what FreeRADIUS cannot use', function (string $field, string $value) {
    addRouter([$field => $value])->assertHasErrors([$field]);

    expect(Nas::query()->count())->toBe(0);
    Process::assertNothingRan();
})->with([
    'IPv6 address' => ['nasname', '2001:db8::1'],
    'host name' => ['nasname', 'router.lan'],
    'short secret' => ['secret', 'abc'],
    'secret with a space' => ['secret', 'two words1'],
    'beta version' => ['routeros_version', '7.15rc1'],
]);

it('tells staff to restore a deleted router instead of reusing its IP or name', function () {
    Nas::factory()->trashed()->create(['nasname' => '10.0.0.1', 'shortname' => 'old-core']);

    addRouter(['nasname' => '10.0.0.1', 'shortname' => 'old-core'])
        ->assertHasErrors(['nasname', 'shortname'])
        ->assertSee('A deleted router uses this IP address. Restore it instead.')
        ->assertSee('A deleted router uses this name. Restore it instead.');
});

it('rewrites Isp-Nas for the customers bound to a router whose IP changes', function () {
    $router = Nas::factory()->create(['nasname' => '10.0.0.1']);
    $bound = Account::factory()->create(['nas_id' => $router->id]);
    DB::table('radcheck')->insert([
        ['username' => $bound->username, 'attribute' => 'Isp-Nas', 'op' => ':=', 'value' => '10.0.0.1'],
        ['username' => 'someone_else', 'attribute' => 'Isp-Nas', 'op' => ':=', 'value' => '10.0.0.1'],
    ]);

    Livewire::test('pages::network.routers')
        ->call('edit', $router->id)
        ->set('nasname', '10.0.0.2')
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('radcheck')->where('username', $bound->username)->value('value'))->toBe('10.0.0.2')
        ->and(DB::table('radcheck')->where('username', 'someone_else')->value('value'))->toBe('10.0.0.1');
    Process::assertRan(RESTART);
});

it('does not restart FreeRADIUS when only the name, description or version changes', function () {
    $router = Nas::factory()->create();

    Livewire::test('pages::network.routers')
        ->call('edit', $router->id)
        ->set('shortname', 'renamed')
        ->set('description', 'Tower 2')
        ->set('routeros_version', '6.49.17')
        ->call('save')
        ->assertHasNoErrors();

    expect($router->fresh()->require_message_auth)->toBeFalse();
    Process::assertNothingRan();
});

it('keeps the router saved and says so when the restart fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'sudo: a password is required', exitCode: 1)]);

    addRouter()->assertDispatched('toast', variant: 'danger', message: 'Router saved. FreeRADIUS did not restart: sudo: a password is required');

    expect(Nas::query()->count())->toBe(1);
});

it('will not delete a router with a session online', function () {
    $router = Nas::factory()->create(['nasname' => '10.0.0.1']);
    DB::table('radacct')->insert([
        'acctsessionid' => 's1', 'acctuniqueid' => 'u1', 'username' => 'alice',
        'nasipaddress' => '10.0.0.1', 'acctstarttime' => now(),
    ]);

    Livewire::test('pages::network.routers')
        ->call('delete', $router->id)
        ->assertDispatched('toast', variant: 'danger');

    expect($router->fresh()->trashed())->toBeFalse();
    Process::assertNothingRan();
});

it('will not delete a router that customers are bound to', function () {
    $router = Nas::factory()->create();
    Account::factory()->create(['nas_id' => $router->id]);

    Livewire::test('pages::network.routers')
        ->call('delete', $router->id)
        ->assertDispatched('toast', variant: 'danger');

    expect($router->fresh()->trashed())->toBeFalse();
});

it('deletes and restores a router, restarting FreeRADIUS each time', function () {
    $router = Nas::factory()->create();

    Livewire::test('pages::network.routers')->call('delete', $router->id);
    expect($router->fresh()->trashed())->toBeTrue();

    Livewire::test('pages::network.routers')->call('restore', $router->id);
    expect($router->fresh()->trashed())->toBeFalse();

    Process::assertRanTimes(RESTART, 2);
});

it('shows whether each router has sent a request since it was last saved', function () {
    Nas::factory()->create(['shortname' => 'talking', 'nasname' => '10.0.0.1', 'updated_at' => now()->subHour()]);
    Nas::factory()->create(['shortname' => 'silent', 'nasname' => '10.0.0.2', 'updated_at' => now()->subHour()]);
    DB::table('radpostauth')->insert([
        ['username' => 'alice', 'reply' => 'Access-Accept', 'nasipaddress' => '10.0.0.1', 'authdate' => now()->subMinutes(5)],
        ['username' => 'bob', 'reply' => 'Access-Accept', 'nasipaddress' => '10.0.0.2', 'authdate' => now()->subDay()],
    ]);

    Livewire::test('pages::network.routers')
        ->assertSeeInOrder(['silent', 'No request received since the last save', 'talking', '5 minutes ago']);
});

it('fills in a generated secret', function () {
    Livewire::test('pages::network.routers')
        ->call('create')
        ->call('generateSecret')
        ->assertSet('secret', fn (string $secret) => preg_match('/^[A-Za-z0-9]{24}$/', $secret) === 1);
});

it('links the Network menu to the routers', function () {
    $this->get(route('dashboard'))->assertSee(route('routers.index'));
});
