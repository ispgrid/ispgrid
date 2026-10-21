<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Support\Usage;
use Livewire\Livewire;

it('shows the network per day for the last 5 days and the top 5 users', function () {
    $this->actingAs(staffHolding(Permission::ViewCustomers));
    $asha = Account::factory()->create(['username' => 'asha']);
    $gigabyte = 1_073_741_824;
    usageDay('asha', now()->toDateString(), 3 * $gigabyte, $gigabyte);
    usageDay('bina', now()->toDateString(), $gigabyte);
    usageDay('asha', now()->subDays(2)->toDateString(), 5 * $gigabyte);
    usageDay('asha', now()->subDays(6)->toDateString(), 50 * $gigabyte);
    foreach (range(1, 6) as $n) {
        usageDay("small{$n}", now()->toDateString(), $n);
    }

    $days = Usage::network(5);
    expect($days)->toHaveCount(5)
        ->and($days[0]['day']->toDateString())->toBe(now()->toDateString())
        ->and($days[0]['download'])->toBe(4 * $gigabyte + 21)
        ->and($days[0]['upload'])->toBe($gigabyte)
        ->and($days[1]['download'])->toBe(0)
        ->and($days[2]['download'])->toBe(5 * $gigabyte)
        ->and(array_column(Usage::top(now()->startOfDay(), 5), 'username'))->toBe(['asha', 'bina', 'small6', 'small5', 'small4']);

    Livewire::test('pages::dashboard')
        ->assertSee(__('Data usage'))
        ->assertSeeInOrder([__('Top 5 users'), 'asha', '4.00 GB', 'bina', '1.00 GB'])
        ->assertSee(route('customers.show', $asha));
});

it('shows no usage cards to staff who do not see customers', function () {
    $this->actingAs(staffHolding(Permission::ViewInvoices));

    Livewire::test('pages::dashboard')->assertDontSee(__('Top 5 users'))->assertDontSee(__('Data usage'));
});
