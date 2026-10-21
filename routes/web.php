<?php

use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\QuickPayController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Quick pay: public, no login.
Route::get('pay', [QuickPayController::class, 'show'])->name('pay.show');
Route::post('pay', [QuickPayController::class, 'find'])->middleware('throttle:quick-pay')->name('pay.find');
Route::post('pay/start', [QuickPayController::class, 'start'])->middleware('throttle:quick-pay')->name('pay.start');
Route::get('pay/result/{publicId}', [QuickPayController::class, 'result'])->where('publicId', '[0-9A-Z]{26}')->name('pay.result');
Route::match(['get', 'post'], 'pay/return/{gateway}', [PaymentGatewayController::class, 'return'])->where('gateway', '[a-z0-9_-]+')->name('pay.return');
Route::post('pay/webhook/{gateway}', [PaymentGatewayController::class, 'webhook'])->where('gateway', '[a-z0-9_-]+')->name('pay.webhook');

Route::middleware(['auth'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('customers', 'pages::customers.index')->name('customers.index');
    Route::livewire('customers/create', 'pages::customers.form')->name('customers.create');
    Route::livewire('customers/{account}', 'pages::customers.show')->name('customers.show');
    Route::livewire('customers/{account}/edit', 'pages::customers.form')->name('customers.edit');
    Route::livewire('invoices', 'pages::invoices.index')->name('invoices.index');
    Route::livewire('invoices/{invoice}', 'pages::invoices.show')->name('invoices.show');
    Route::livewire('payments', 'pages::payments.index')->name('payments.index');
    Route::livewire('payments/{payment}', 'pages::payments.show')->name('payments.show')->withTrashed();
    Route::get('invoices/{invoice}/pdf', InvoicePdfController::class)->name('invoices.pdf');
    Route::get('payments/{payment}/receipt', PaymentReceiptController::class)->name('payments.receipt')->withTrashed();
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
