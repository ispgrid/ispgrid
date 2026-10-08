<?php

use App\Models\AppSetting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->me = User::factory()->create(['name' => 'Asha']);
    $this->actingAs($this->me);
});

it('lists changes newest first with who made them and what changed', function () {
    AppSetting::put('currency_code', 'INR');
    AppSetting::put('currency_code', 'USD');

    Livewire::test('pages::activity.index')
        ->assertSeeInOrder(['Updated', 'App setting', 'currency_code', 'INR', 'USD', 'Created'])
        ->assertSee('Asha');
});

it('keeps the name of a staff member who was later disabled', function () {
    $ravi = User::factory()->create(['name' => 'Ravi']);
    $this->actingAs($ravi);
    AppSetting::put('date_format', 'Y-m-d');

    $this->actingAs($this->me);
    $ravi->delete();

    Livewire::test('pages::activity.index')
        ->set('staff', (string) $ravi->id)
        ->assertSee('date_format')
        ->assertDontSee('System');
    // The staff filter lists Ravi either way; "System" would show if the row lost its causer.
});

it('shows System for changes made without a login', function () {
    auth()->logout();
    AppSetting::put('notice_phone', '1800 000 000');
    $this->actingAs($this->me);

    Livewire::test('pages::activity.index')
        ->set('type', (new AppSetting)->getMorphClass())
        ->assertSee('System')
        ->assertSee('notice_phone');
});

it('filters by staff member', function () {
    $ravi = User::factory()->create(['name' => 'Ravi']);
    $this->actingAs($ravi);
    AppSetting::put('currency_symbol', '$');
    $this->actingAs($this->me);
    AppSetting::put('phone_digits', '9');

    Livewire::test('pages::activity.index')
        ->set('staff', (string) $this->me->id)
        ->assertSee('phone_digits')
        ->assertDontSee('currency_symbol');
});

it('calls a deleted staff login Disabled', function () {
    User::factory()->create(['name' => 'Ravi'])->delete();

    Livewire::test('pages::activity.index')
        ->assertSee('Disabled');
});

it('links the System menu to the activity log', function () {
    $this->get(route('dashboard'))->assertSee(route('activity.index'));
});
