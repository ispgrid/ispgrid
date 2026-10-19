<?php

use App\Enums\Permission;
use App\Models\Account;
use Livewire\Livewire;

/*
 * The customer page shows each action only to roles with its box, and the server refuses it to the others.
 */

it('shows a customer action only with its box', function (string $html, Permission $permission, string $tab = 'details') {
    $account = Account::factory()->create();

    $this->actingAs(staffHolding(Permission::ViewCustomers));
    Livewire::withQueryParams(['tab' => $tab])->test('pages::customers.show', ['account' => $account->id])->assertDontSeeHtml($html);

    $this->actingAs(staffHolding(Permission::ViewCustomers, $permission));
    Livewire::withQueryParams(['tab' => $tab])->test('pages::customers.show', ['account' => $account->id])->assertSeeHtml($html);
})->with([
    'recharge' => ['wire:click="openRecharge"', Permission::RechargeCustomers],
    'edit' => ['/edit"', Permission::EditCustomers],
    'change status' => ["name: 'change-status'", Permission::ChangeCustomerStatus],
    'reset devices' => ['wire:click="resetDevices"', Permission::ResetCustomerDevices, 'connection'],
    'delete' => ['wire:click="delete"', Permission::DeleteCustomers],
]);

it('refuses a customer action without its box', function (string $method, Permission $permission) {
    $account = Account::factory()->create();

    $this->actingAs(staffLacking($permission));

    Livewire::test('pages::customers.show', ['account' => $account->id])->call($method)->assertForbidden();
})->with([
    'open recharge' => ['openRecharge', Permission::RechargeCustomers],
    'recharge' => ['recharge', Permission::RechargeCustomers],
    'change status' => ['changeStatus', Permission::ChangeCustomerStatus],
    'reset devices' => ['resetDevices', Permission::ResetCustomerDevices],
    'unlock' => ['unlock', Permission::ResetCustomerDevices],
    'disconnect' => ['disconnect', Permission::DisconnectSessions],
    'delete' => ['delete', Permission::DeleteCustomers],
]);

it('refuses to restore a customer without the delete box', function () {
    $account = Account::factory()->create();
    $account->delete();

    $this->actingAs(staffLacking(Permission::DeleteCustomers));

    Livewire::test('pages::customers.show', ['account' => $account->id])
        ->assertDontSeeHtml('wire:click="restore"')
        ->call('restore')
        ->assertForbidden();
});

it('hides the login log link from roles that cannot open it', function () {
    $account = Account::factory()->create();

    $this->actingAs(staffLacking(Permission::ViewLoginLog));

    Livewire::test('pages::customers.show', ['account' => $account->id])
        ->assertDontSee(route('login-log.index', ['username' => $account->username]));
});
