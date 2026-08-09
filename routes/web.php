<?php

use App\Http\Controllers\Store\CoinsQuoteController;
use App\Http\Controllers\Store\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/coins/quote', CoinsQuoteController::class)->name('coins.quote');

Route::prefix('{locale}')
    ->whereIn('locale', config('store.locales'))
    ->group(function (): void {
        Route::get('/', HomeController::class)->name('localized.home');
        Route::get('/coins/quote', CoinsQuoteController::class)->name('localized.coins.quote');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
