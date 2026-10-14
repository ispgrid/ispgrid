<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Ip;
use Database\Seeders\RadiusAttributeSeeder;
use Livewire\Livewire;

/*
 * Without "See customer passwords" the edit form never receives the password, and leaving it empty keeps it.
 */

beforeEach(function () {
    $this->seed(RadiusAttributeSeeder::class);
});

it('sends the password to the edit form only with the box', function () {
    $account = Account::factory()->create(['password' => 'secret12']);

    $this->actingAs(staffHolding(Permission::EditCustomers, Permission::ViewCustomerPasswords));
    Livewire::test('pages::customers.form', ['account' => $account->id])->assertSet('password', 'secret12');

    $this->actingAs(staffHolding(Permission::EditCustomers));
    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->assertSet('password', '')
        ->assertDontSeeHtml('secret12')
        ->assertSee(__('Leave empty to keep the current password.'));
});

it('keeps the password when the field is left empty without the box', function () {
    $account = Account::factory()->create(['password' => 'secret12', 'fullname' => 'Alice Rao']);

    $this->actingAs(staffHolding(Permission::EditCustomers));
    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('fullname', 'Alice Rao Sharma')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh())->password->toBe('secret12')->fullname->toBe('Alice Rao Sharma');
});

it('changes the password typed without the box', function () {
    $account = Account::factory()->create(['password' => 'secret12']);

    $this->actingAs(staffHolding(Permission::EditCustomers));
    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('password', 'newpass34')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->password)->toBe('newpass34');
});

it('still asks for a password when an IPoE customer becomes PPPoE without the box', function () {
    $account = Account::factory()->ipoe()->create(['password' => 'generated123', 'ip_id' => Ip::factory()->create(['address' => '172.16.30.20'])->id]);

    $this->actingAs(staffHolding(Permission::EditCustomers));
    Livewire::test('pages::customers.form', ['account' => $account->id])
        ->set('service_type', 'PPP')
        ->call('save')
        ->assertHasErrors(['password']);
});

it('still asks for the password of a new PPPoE customer without the box', function () {
    $this->actingAs(staffHolding(Permission::AddCustomers));

    Livewire::test('pages::customers.form')
        ->set('fullname', 'Alice Rao')
        ->set('service_type', 'PPP')
        ->set('username', 'alice')
        ->call('save')
        ->assertHasErrors(['password']);
});
