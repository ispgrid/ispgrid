<?php

use App\Models\Account;
use App\Models\Ip;
use App\Models\IpRange;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

function addRange(array $fields): Testable
{
    $page = Livewire::test('pages::network.ip-ranges')->call('create');

    foreach ($fields + ['name' => 'Customers'] as $field => $value) {
        $page->set($field, $value);
    }

    return $page->call('save');
}

it('adds a range and fills the inventory with its hosts', function () {
    addRange(['network' => '10.50.0.9/29'])->assertHasNoErrors();

    $range = IpRange::query()->sole();

    expect($range->network)->toBe('10.50.0.8/29')
        ->and($range->ips()->orderBy('id')->pluck('address')->all())
        ->toBe(['10.50.0.9', '10.50.0.10', '10.50.0.11', '10.50.0.12', '10.50.0.13', '10.50.0.14']);
});

it('leaves the gateway out of the inventory', function () {
    addRange(['network' => '10.60.0.0/29', 'gateway' => '10.60.0.1'])->assertHasNoErrors();

    expect(Ip::query()->orderBy('id')->pluck('address')->all())->toBe(['10.60.0.2', '10.60.0.3', '10.60.0.4', '10.60.0.5', '10.60.0.6']);
});

it('does not write one activity entry per address', function () {
    addRange(['network' => '10.70.0.0/24']);

    expect(Activity::query()->where('subject_type', (new Ip)->getMorphClass())->count())->toBe(0);
});

it('saves DNS servers in canonical form', function () {
    addRange(['network' => '10.80.0.0/30', 'dns1' => ' 1.1.1.1 ', 'dns2' => '2001:4860:4860:0:0:0:0:8888'])->assertHasNoErrors();

    expect(IpRange::query()->sole()->only(['dns1', 'dns2']))->toBe(['dns1' => '1.1.1.1', 'dns2' => '2001:4860:4860::8888']);
});

it('refuses a range that overlaps a live range', function () {
    IpRange::factory()->create(['name' => 'Tower A', 'network' => '10.90.0.0/24']);

    addRange(['network' => '10.90.0.128/25'])
        ->assertHasErrors(['network'])
        ->assertSee('Overlaps the range Tower A (10.90.0.0/24).');
});

it('refuses a range that overlaps a deleted range and says to restore it', function () {
    IpRange::factory()->trashed()->create(['name' => 'Old tower', 'network' => '10.91.0.0/24']);

    addRange(['network' => '10.91.0.0/24'])
        ->assertHasErrors(['network'])
        ->assertSee('Overlaps the deleted range Old tower (10.91.0.0/24). Restore it instead.');
});

it('refuses what is not a usable range', function (array $fields, string $field) {
    addRange($fields)->assertHasErrors([$field]);

    expect(IpRange::query()->count())->toBe(0);
})->with([
    'bigger than /20' => [['network' => '10.0.0.0/19'], 'network'],
    'IPv6' => [['network' => '2001:db8::/120'], 'network'],
    'no prefix' => [['network' => '10.0.0.0'], 'network'],
    'gateway outside' => [['network' => '10.1.0.0/24', 'gateway' => '10.2.0.1'], 'gateway'],
    'gateway is the broadcast' => [['network' => '10.1.0.0/24', 'gateway' => '10.1.0.255'], 'gateway'],
    'bad DNS' => [['network' => '10.1.0.0/24', 'dns1' => 'dns.google'], 'dns1'],
]);

it('moves the gateway out of the inventory and puts the old one back', function () {
    addRange(['network' => '10.20.0.0/29', 'gateway' => '10.20.0.1']);
    $range = IpRange::query()->sole();

    Livewire::test('pages::network.ip-ranges')
        ->call('edit', $range->id)
        ->set('gateway', '10.20.0.6')
        ->call('save')
        ->assertHasNoErrors();

    expect(Ip::query()->pluck('address')->all())->toContain('10.20.0.1')->not->toContain('10.20.0.6')
        ->and(Ip::onlyTrashed()->pluck('address')->all())->toBe(['10.20.0.6']);
});

it('will not make an address a customer has the gateway', function () {
    addRange(['network' => '10.21.0.0/29', 'gateway' => '10.21.0.1']);
    $range = IpRange::query()->sole();
    Account::factory()->create(['ip_id' => Ip::query()->where('address', '10.21.0.6')->value('id')]);

    Livewire::test('pages::network.ip-ranges')
        ->call('edit', $range->id)
        ->set('gateway', '10.21.0.6')
        ->call('save')
        ->assertHasErrors(['gateway']);

    expect($range->fresh()->gateway)->toBe('10.21.0.1');
});

it('will not delete a range with an address given out', function () {
    addRange(['network' => '10.22.0.0/30']);
    $range = IpRange::query()->sole();
    Ip::query()->first()->update(['is_used' => true]);

    Livewire::test('pages::network.ip-ranges')
        ->call('delete', $range->id)
        ->assertDispatched('toast', variant: 'danger');

    expect($range->fresh()->trashed())->toBeFalse();
});

it('restores a range with only the addresses its delete removed', function () {
    addRange(['network' => '10.23.0.0/29', 'gateway' => '10.23.0.1']);
    $range = IpRange::query()->sole();

    Livewire::test('pages::network.ip-range', ['ipRange' => $range->id])
        ->call('removeIp', Ip::query()->where('address', '10.23.0.3')->value('id'));

    $this->travel(1)->minutes();
    Livewire::test('pages::network.ip-ranges')->call('delete', $range->id);
    expect(Ip::query()->count())->toBe(0);

    $this->travel(1)->minutes();
    Livewire::test('pages::network.ip-ranges')->call('restore', $range->id);

    expect($range->fresh()->trashed())->toBeFalse()
        ->and(Ip::query()->count())->toBe(4)
        ->and(Ip::onlyTrashed()->pluck('address')->all())->toBe(['10.23.0.3']);
});

it('shows a range with the details a technician needs', function () {
    addRange(['network' => '10.24.0.0/29', 'gateway' => '10.24.0.1', 'dns1' => '1.1.1.1']);

    Livewire::test('pages::network.ip-range', ['ipRange' => IpRange::query()->value('id')])
        ->assertSee('255.255.255.248')
        ->assertSee('10.24.0.1')
        ->assertSee('1.1.1.1')
        ->assertSeeInOrder(['10.24.0.2', '10.24.0.3', '10.24.0.6']);
});

it('takes a free address out of the inventory and puts it back', function () {
    addRange(['network' => '10.25.0.0/30']);
    $ip = Ip::query()->firstOrFail();

    Livewire::test('pages::network.ip-range', ['ipRange' => $ip->ip_range_id])->call('removeIp', $ip->id);
    expect($ip->fresh()->trashed())->toBeTrue();

    Livewire::test('pages::network.ip-range', ['ipRange' => $ip->ip_range_id])->call('restoreIp', $ip->id);
    expect($ip->fresh()->trashed())->toBeFalse();
});

it('will not take out an address a customer has', function () {
    addRange(['network' => '10.26.0.0/30']);
    $ip = Ip::query()->firstOrFail();
    Account::factory()->create(['ip_id' => $ip->id]);

    Livewire::test('pages::network.ip-range', ['ipRange' => $ip->ip_range_id])
        ->call('removeIp', $ip->id)
        ->assertDispatched('toast', variant: 'danger');

    expect($ip->fresh()->trashed())->toBeFalse();
});

it('filters the addresses of a range', function () {
    addRange(['network' => '10.27.0.0/29', 'gateway' => '10.27.0.1']);
    $account = Account::factory()->create(['ip_id' => Ip::query()->where('address', '10.27.0.4')->value('id')]);

    Livewire::test('pages::network.ip-range', ['ipRange' => IpRange::query()->value('id')])
        ->set('show', 'given-out')
        ->assertSee('10.27.0.4')
        ->assertSee($account->username)
        ->assertDontSee('10.27.0.5');
});

it('opens a range page and links the Network menu to the ranges', function () {
    $range = IpRange::factory()->create();

    $this->get(route('ip-ranges.show', $range))->assertOk();
    $this->get(route('dashboard'))->assertSee(route('ip-ranges.index'));
});

it('will not put the gateway back into the inventory', function () {
    addRange(['network' => '10.28.0.0/29', 'gateway' => '10.28.0.1']);
    $range = IpRange::query()->sole();

    Livewire::test('pages::network.ip-ranges')
        ->call('edit', $range->id)
        ->set('gateway', '10.28.0.6')
        ->call('save');

    $gateway = Ip::onlyTrashed()->where('address', '10.28.0.6')->sole();

    Livewire::test('pages::network.ip-range', ['ipRange' => $range->id])
        ->assertDontSee('wire:click="restoreIp('.$gateway->id.')"', false)
        ->call('restoreIp', $gateway->id)
        ->assertDispatched('toast', variant: 'danger');

    expect($gateway->fresh()->trashed())->toBeTrue();
});
