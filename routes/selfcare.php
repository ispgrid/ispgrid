<?php

use App\Http\Controllers\SelfcareLogoutController;
use Illuminate\Support\Facades\Route;

// Selfcare: the customer portal, on its own guard. Every page is not found while Selfcare is off.
Route::prefix('selfcare')->name('selfcare.')->group(function () {
    Route::livewire('/', 'pages::selfcare.login')->name('login');
    Route::post('logout', SelfcareLogoutController::class)->name('logout');

    Route::middleware('selfcare')->group(function () {
        Route::livewire('password', 'pages::selfcare.password')->name('password');
        Route::livewire('home', 'pages::selfcare.dashboard')->name('dashboard');
    });
});
