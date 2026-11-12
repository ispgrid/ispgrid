<?php

use App\Http\Controllers\SelfcareDocumentController;
use App\Http\Controllers\SelfcareLogoutController;
use Illuminate\Support\Facades\Route;

// Selfcare: the customer portal, on its own guard. Every page is not found while Selfcare is off.
Route::prefix('selfcare')->name('selfcare.')->group(function () {
    Route::livewire('/', 'pages::selfcare.login')->name('login');
    Route::post('logout', SelfcareLogoutController::class)->name('logout');

    Route::middleware('selfcare')->group(function () {
        Route::livewire('password', 'pages::selfcare.password')->name('password');
        Route::livewire('home', 'pages::selfcare.dashboard')->name('dashboard');
        Route::livewire('connections', 'pages::selfcare.connections')->name('connections');
        Route::livewire('pppoe-password', 'pages::selfcare.pppoe-password')->name('pppoe-password');
        Route::get('invoices/{invoice}', [SelfcareDocumentController::class, 'invoice'])->whereNumber('invoice')->name('invoice');
        Route::get('receipts/{payment}', [SelfcareDocumentController::class, 'receipt'])->whereNumber('payment')->name('receipt');
    });
});
