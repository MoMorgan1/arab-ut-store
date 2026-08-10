<?php

use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CoinsCartController;
use App\Http\Controllers\Store\CoinsCartResumeController;
use App\Http\Controllers\Store\CoinsQuoteController;
use App\Http\Controllers\Store\HomeController;
use App\Http\Controllers\Store\SimpleStorePageController;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\ValidateCoinsCartResume;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/coins/quote', CoinsQuoteController::class)->name('coins.quote');
Route::get('/cart', CartController::class)->name('store.cart');
Route::post('/cart/items/coins', CoinsCartController::class)
    ->middleware([NoStore::class, 'auth', 'throttle:coins-cart'])
    ->name('cart.items.coins.store');
Route::get('/cart/items/coins/resume', CoinsCartResumeController::class)
    ->middleware([NoStore::class, ValidateCoinsCartResume::class, 'auth'])
    ->name('cart.items.coins.resume');

$simpleStorePages = [
    'sbc' => '/sbc',
    'fut_champions' => '/fut-champions',
    'privacy' => '/privacy',
    'returns' => '/returns',
    'warranty' => '/warranty',
    'ea_backup_codes' => '/ea-backup-codes',
    'terms' => '/terms',
];

foreach ($simpleStorePages as $page => $uri) {
    Route::get($uri, SimpleStorePageController::class)
        ->defaults('storePage', $page)->name("store.{$page}");
}

Route::prefix('{locale}')
    ->whereIn('locale', config('store.locales'))
    ->group(function () use ($simpleStorePages): void {
        Route::get('/', HomeController::class)->name('localized.home');
        Route::get('/coins/quote', CoinsQuoteController::class)->name('localized.coins.quote');
        Route::get('/cart', CartController::class)->name('localized.store.cart');
        Route::post('/cart/items/coins', CoinsCartController::class)
            ->middleware([NoStore::class, 'auth', 'throttle:coins-cart'])
            ->name('localized.cart.items.coins.store');
        Route::get('/cart/items/coins/resume', CoinsCartResumeController::class)
            ->middleware([NoStore::class, ValidateCoinsCartResume::class, 'auth'])
            ->name('localized.cart.items.coins.resume');

        foreach ($simpleStorePages as $page => $uri) {
            Route::get($uri, SimpleStorePageController::class)
                ->defaults('storePage', $page)->name("localized.store.{$page}");
        }
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
