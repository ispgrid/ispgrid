<?php

use App\Http\Controllers\InvoicePdfController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('customers', 'pages::customers.index')->name('customers.index');
    Route::livewire('customers/create', 'pages::customers.form')->name('customers.create');
    Route::livewire('customers/{account}', 'pages::customers.show')->name('customers.show');
    Route::livewire('customers/{account}/edit', 'pages::customers.form')->name('customers.edit');
    Route::livewire('invoices/{invoice}', 'pages::invoices.show')->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', InvoicePdfController::class)->name('invoices.pdf');
    Route::livewire('online', 'pages::online.index')->name('online.index');
    Route::livewire('login-log', 'pages::login-log.index')->name('login-log.index');
    Route::livewire('plans', 'pages::plans.index')->name('plans.index');
    Route::livewire('plans/create', 'pages::plans.form')->name('plans.create');
    Route::livewire('plans/{plan}', 'pages::plans.show')->name('plans.show');
    Route::livewire('plans/{plan}/edit', 'pages::plans.form')->name('plans.edit');
    Route::livewire('network/routers', 'pages::network.routers')->name('routers.index');
    Route::livewire('network/pools', 'pages::network.pools')->name('pools.index');
    Route::livewire('network/ip-ranges', 'pages::network.ip-ranges')->name('ip-ranges.index');
    Route::livewire('network/ip-ranges/{ipRange}', 'pages::network.ip-range')->name('ip-ranges.show');
    Route::livewire('network/address-lists', 'pages::network.address-lists')->name('address-lists.index');
    Route::livewire('staff', 'pages::staff.index')->name('staff.index');
    Route::livewire('staff/roles', 'pages::staff.roles')->name('roles.index');
    Route::livewire('staff/roles/create', 'pages::staff.role-form')->name('roles.create');
    Route::livewire('staff/roles/{role}/edit', 'pages::staff.role-form')->name('roles.edit');
    Route::livewire('system/app-settings', 'pages::system.app-settings')->name('app-settings.edit');
    Route::livewire('system/billing', 'pages::system.billing-settings')->name('billing-settings.edit');
    Route::livewire('system/addons', 'pages::system.addons')->name('addons.index');
    Route::livewire('system/freeradius', 'pages::system.radius-settings')->name('radius-settings.edit');
    Route::livewire('system/connection-types', 'pages::system.connection-types')->name('connection-types.index');
    Route::livewire('notifications/providers', 'pages::notifications.providers')->name('notifications.providers');
    Route::livewire('notifications/rules', 'pages::notifications.rules')->name('notification-rules.index');
    Route::livewire('notifications/rules/create', 'pages::notifications.rule-form')->name('notification-rules.create');
    Route::livewire('notifications/rules/{rule}/edit', 'pages::notifications.rule-form')->name('notification-rules.edit');
    Route::livewire('notifications/log', 'pages::notifications.log')->name('notification-log.index');
    Route::livewire('activity', 'pages::activity.index')->name('activity.index');
    Route::livewire('system/import', 'pages::system.import')->name('import.index');
});

require __DIR__.'/settings.php';
