<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('network/routers', 'pages::network.routers')->name('routers.index');
    Route::livewire('network/pools', 'pages::network.pools')->name('pools.index');
    Route::livewire('network/ip-ranges', 'pages::network.ip-ranges')->name('ip-ranges.index');
    Route::livewire('network/ip-ranges/{ipRange}', 'pages::network.ip-range')->name('ip-ranges.show');
    Route::livewire('network/address-lists', 'pages::network.address-lists')->name('address-lists.index');
    Route::livewire('staff', 'pages::staff.index')->name('staff.index');
    Route::livewire('system/app-settings', 'pages::system.app-settings')->name('app-settings.edit');
    Route::livewire('system/connection-types', 'pages::system.connection-types')->name('connection-types.index');
    Route::livewire('activity', 'pages::activity.index')->name('activity.index');
});

require __DIR__.'/settings.php';
