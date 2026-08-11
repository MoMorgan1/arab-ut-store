<?php

use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CartItemCredentialsController;
use App\Http\Controllers\Store\CatalogCartController;
use App\Http\Controllers\Store\CatalogProductController;
use App\Http\Controllers\Store\CategoryController;
use App\Http\Controllers\Store\CategoryProductController;
use App\Http\Controllers\Store\CoinsCartController;
use App\Http\Controllers\Store\CoinsQuoteController;
use App\Http\Controllers\Store\HomeController;
use App\Http\Controllers\Store\ReviewsController;
use App\Http\Controllers\Store\SimpleStorePageController;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\RequireCatalogCartJson;
use App\Http\Middleware\RequireCoinsCartJson;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

Route::get('/', HomeController::class)->name('home');
Route::get('/coins/quote', CoinsQuoteController::class)->name('coins.quote');
Route::get('/cart', CartController::class)->name('store.cart');
Route::get('/cart/items/{cartItem}/credentials', [CartItemCredentialsController::class, 'show'])
    ->middleware([NoStore::class, 'throttle:coins-cart'])
    ->name('cart.items.credentials.show');
Route::patch('/cart/items/{cartItem}/credentials', [CartItemCredentialsController::class, 'update'])
    ->middleware([NoStore::class, RequireCoinsCartJson::class, 'throttle:coins-cart'])
    ->name('cart.items.credentials.update');
Route::get('/reviews', ReviewsController::class)->name('store.reviews');
Route::post('/cart/items/coins', CoinsCartController::class)
    ->middleware([NoStore::class, RequireCoinsCartJson::class, 'throttle:coins-cart'])
    ->name('cart.items.coins.store');
Route::post('/cart/items/catalog', CatalogCartController::class)
    ->middleware([NoStore::class, RequireCatalogCartJson::class, 'throttle:coins-cart'])
    ->name('cart.items.catalog.store');

$simpleStorePages = [
    'privacy' => '/privacy',
    'returns' => '/returns',
    'warranty' => '/warranty',
    'ea_backup_codes' => '/ea-backup-codes',
    'terms' => '/terms',
];
$catalogCategories = [
    'sbc' => ['/sbc', 'sbc'],
    'objectives' => ['/objectives', 'objectives'],
];
$catalogProducts = [
    'fut_champions' => ['/fut-champions', 'fut_champions'],
    'rivals' => ['/rivals', 'rivals'],
];
$localizedLoginMiddleware = array_filter([
    'guest:'.config('fortify.guard'),
    config('fortify.limiters.login')
        ? 'throttle:'.config('fortify.limiters.login')
        : null,
]);

foreach ($simpleStorePages as $page => $uri) {
    Route::get($uri, SimpleStorePageController::class)
        ->defaults('storePage', $page)->name("store.{$page}");
}

foreach ($catalogCategories as $name => [$uri, $service]) {
    Route::get($uri, CategoryController::class)
        ->defaults('service', $service)->name("store.{$name}");
    Route::get("{$uri}/{slug}", CategoryProductController::class)
        ->defaults('service', $service)->name("store.{$name}.show");
}

foreach ($catalogProducts as $name => [$uri, $service]) {
    Route::get($uri, CatalogProductController::class)
        ->defaults('service', $service)->name("store.{$name}");
}

Route::prefix('{locale}')
    ->whereIn('locale', config('store.locales'))
    ->group(function () use ($catalogCategories, $catalogProducts, $localizedLoginMiddleware, $simpleStorePages): void {
        Route::get('/', HomeController::class)->name('localized.home');
        Route::get('/coins/quote', CoinsQuoteController::class)->name('localized.coins.quote');
        Route::get('/cart', CartController::class)->name('localized.store.cart');
        Route::get('/cart/items/{cartItem}/credentials', [CartItemCredentialsController::class, 'show'])
            ->middleware([NoStore::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.credentials.show');
        Route::patch('/cart/items/{cartItem}/credentials', [CartItemCredentialsController::class, 'update'])
            ->middleware([NoStore::class, RequireCoinsCartJson::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.credentials.update');
        Route::get('/reviews', ReviewsController::class)->name('localized.store.reviews');
        Route::post('/cart/items/coins', CoinsCartController::class)
            ->middleware([NoStore::class, RequireCoinsCartJson::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.coins.store');
        Route::post('/cart/items/catalog', CatalogCartController::class)
            ->middleware([NoStore::class, RequireCatalogCartJson::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.catalog.store');

        Route::get('/login', [AuthenticatedSessionController::class, 'create'])
            ->middleware(['guest:'.config('fortify.guard')])
            ->name('localized.login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware($localizedLoginMiddleware)
            ->name('localized.login.store');

        if (Features::enabled(Features::registration())) {
            Route::get('/register', [RegisteredUserController::class, 'create'])
                ->middleware(['guest:'.config('fortify.guard')])
                ->name('localized.register');
            Route::post('/register', [RegisteredUserController::class, 'store'])
                ->middleware(['guest:'.config('fortify.guard')])
                ->name('localized.register.store');
        }

        if (Features::enabled(Features::resetPasswords())) {
            Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
                ->middleware(['guest:'.config('fortify.guard')])
                ->name('localized.password.request');
            Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
                ->middleware(['guest:'.config('fortify.guard')])
                ->name('localized.password.email');
            Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
                ->middleware(['guest:'.config('fortify.guard')])
                ->name('localized.password.reset');
            Route::post('/reset-password', [NewPasswordController::class, 'store'])
                ->middleware(['guest:'.config('fortify.guard')])
                ->name('localized.password.update');
        }

        foreach ($simpleStorePages as $page => $uri) {
            Route::get($uri, SimpleStorePageController::class)
                ->defaults('storePage', $page)->name("localized.store.{$page}");
        }

        foreach ($catalogCategories as $name => [$uri, $service]) {
            Route::get($uri, CategoryController::class)
                ->defaults('service', $service)->name("localized.store.{$name}");
            Route::get("{$uri}/{slug}", CategoryProductController::class)
                ->defaults('service', $service)->name("localized.store.{$name}.show");
        }

        foreach ($catalogProducts as $name => [$uri, $service]) {
            Route::get($uri, CatalogProductController::class)
                ->defaults('service', $service)->name("localized.store.{$name}");
        }
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
