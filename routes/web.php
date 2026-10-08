<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('staff', 'pages::staff.index')->name('staff.index');
    Route::livewire('system/app-settings', 'pages::system.app-settings')->name('app-settings.edit');
    Route::livewire('system/connection-types', 'pages::system.connection-types')->name('connection-types.index');
});

require __DIR__.'/settings.php';
