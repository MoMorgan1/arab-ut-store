<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'store/home')->name('home');

Route::prefix('{locale}')
    ->whereIn('locale', config('store.locales'))
    ->group(function (): void {
        Route::inertia('/', 'store/home')->name('localized.home');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
