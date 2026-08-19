<?php

use App\Http\Controllers\Admin\PaylinkRefundController;
use App\Http\Controllers\Auth\GoogleAuthenticationController;
use App\Http\Controllers\Auth\WhatsAppLoginController;
use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CartItemController;
use App\Http\Controllers\Store\CartItemCredentialsController;
use App\Http\Controllers\Store\CatalogCartController;
use App\Http\Controllers\Store\CategoryController;
use App\Http\Controllers\Store\CategoryProductController;
use App\Http\Controllers\Store\CheckoutPhoneVerificationController;
use App\Http\Controllers\Store\CoinsCartController;
use App\Http\Controllers\Store\CoinsQuoteController;
use App\Http\Controllers\Store\FutChampionsCartController;
use App\Http\Controllers\Store\HomeController;
use App\Http\Controllers\Store\ManualServiceProductController;
use App\Http\Controllers\Store\OrderController;
use App\Http\Controllers\Store\PaylinkCheckoutController;
use App\Http\Controllers\Store\PaylinkOrderPaymentController;
use App\Http\Controllers\Store\PaylinkReturnController;
use App\Http\Controllers\Store\ReviewsController;
use App\Http\Controllers\Store\RivalsCartController;
use App\Http\Controllers\Store\SbcCartController;
use App\Http\Controllers\Store\SimpleStorePageController;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\RequireCatalogCartJson;
use App\Http\Middleware\RequireCoinsCartJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

Route::get('/', HomeController::class)->name('home');
Route::get('/coins/quote', CoinsQuoteController::class)->name('coins.quote');
Route::get('/cart', CartController::class)
    ->middleware(NoStore::class)
    ->name('store.cart');
Route::post('/checkout/paylink', PaylinkCheckoutController::class)
    ->middleware([NoStore::class, 'auth', RequireCatalogCartJson::class, 'throttle:coins-cart'])
    ->name('store.checkout.paylink');
Route::post('/checkout/phone/code', [CheckoutPhoneVerificationController::class, 'send'])
    ->middleware([NoStore::class, 'auth', 'throttle:whatsapp-login-send'])
    ->name('store.checkout.phone.send');
Route::post('/checkout/phone/verify', [CheckoutPhoneVerificationController::class, 'verify'])
    ->middleware([NoStore::class, 'auth', 'throttle:whatsapp-login-verify'])
    ->name('store.checkout.phone.verify');
Route::get('/payments/paylink/callback', PaylinkReturnController::class)
    ->middleware([NoStore::class, 'auth'])->name('payments.paylink.callback');
Route::get('/payments/paylink/cancel', PaylinkReturnController::class)
    ->middleware([NoStore::class, 'auth'])->name('payments.paylink.cancel');
Route::get('/orders/{order}', OrderController::class)
    ->middleware(['auth', NoStore::class])->name('store.orders.show');
Route::post('/orders/{order:public_id}/payments/paylink', PaylinkOrderPaymentController::class)
    ->middleware(['auth', NoStore::class, 'throttle:coins-cart'])
    ->name('store.orders.paylink-payment');
Route::post('/admin/api/orders/{order:public_id}/refund', PaylinkRefundController::class)
    ->middleware(['auth', NoStore::class, 'throttle:staff-payments'])
    ->name('admin.orders.paylink-refund');
Route::get('/cart/items/{cartItem}/credentials', [CartItemCredentialsController::class, 'show'])
    ->middleware([NoStore::class, 'throttle:coins-cart'])
    ->name('cart.items.credentials.show');
Route::patch('/cart/items/{cartItem}/credentials', [CartItemCredentialsController::class, 'update'])
    ->middleware([NoStore::class, RequireCoinsCartJson::class, 'throttle:coins-cart'])
    ->name('cart.items.credentials.update');
Route::delete('/cart/items/{cartItem}', [CartItemController::class, 'destroy'])
    ->middleware([NoStore::class, 'throttle:coins-cart'])
    ->name('cart.items.destroy');
Route::get('/reviews', ReviewsController::class)->name('store.reviews');
Route::post('/cart/items/coins', CoinsCartController::class)
    ->middleware([NoStore::class, RequireCoinsCartJson::class, 'throttle:coins-cart'])
    ->name('cart.items.coins.store');
Route::post('/cart/items/catalog', CatalogCartController::class)
    ->middleware([NoStore::class, RequireCatalogCartJson::class, 'throttle:coins-cart'])
    ->name('cart.items.catalog.store');
Route::post('/cart/items/sbc', SbcCartController::class)
    ->middleware([NoStore::class, RequireCatalogCartJson::class, 'throttle:coins-cart'])
    ->name('cart.items.sbc.store');
Route::post('/cart/items/fut-champions', FutChampionsCartController::class)
    ->middleware([NoStore::class, 'throttle:coins-cart'])
    ->name('cart.items.fut-champions.store');
Route::post('/cart/items/rivals', RivalsCartController::class)
    ->middleware([NoStore::class, 'throttle:coins-cart'])
    ->name('cart.items.rivals.store');
Route::get('/auth/google/redirect', [GoogleAuthenticationController::class, 'redirect'])
    ->middleware(['guest:'.config('fortify.guard')])
    ->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthenticationController::class, 'callback'])
    ->middleware(['guest:'.config('fortify.guard')])
    ->name('auth.google.callback');
Route::post('/auth/whatsapp/code', [WhatsAppLoginController::class, 'send'])
    ->middleware(['guest:'.config('fortify.guard'), 'throttle:whatsapp-login-send'])
    ->name('auth.whatsapp.send');
Route::post('/auth/whatsapp/verify', [WhatsAppLoginController::class, 'verify'])
    ->middleware(['guest:'.config('fortify.guard'), 'throttle:whatsapp-login-verify'])
    ->name('auth.whatsapp.verify');

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
$manualServiceProducts = [
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

foreach ($manualServiceProducts as $name => [$uri, $service]) {
    Route::get($uri, ManualServiceProductController::class)
        ->defaults('service', $service)->name("store.{$name}");
}

Route::prefix('{locale}')
    ->whereIn('locale', config('store.locales'))
    ->group(function () use ($catalogCategories, $localizedLoginMiddleware, $manualServiceProducts, $simpleStorePages): void {
        Route::get('/', HomeController::class)->name('localized.home');
        Route::get('/coins/quote', CoinsQuoteController::class)->name('localized.coins.quote');
        Route::get('/cart', CartController::class)
            ->middleware(NoStore::class)
            ->name('localized.store.cart');
        Route::post('/checkout/paylink', PaylinkCheckoutController::class)
            ->middleware([NoStore::class, 'auth', RequireCatalogCartJson::class, 'throttle:coins-cart'])
            ->name('localized.store.checkout.paylink');
        Route::post('/checkout/phone/code', [CheckoutPhoneVerificationController::class, 'send'])
            ->middleware([NoStore::class, 'auth', 'throttle:whatsapp-login-send'])
            ->name('localized.store.checkout.phone.send');
        Route::post('/checkout/phone/verify', [CheckoutPhoneVerificationController::class, 'verify'])
            ->middleware([NoStore::class, 'auth', 'throttle:whatsapp-login-verify'])
            ->name('localized.store.checkout.phone.verify');
        Route::get('/payments/paylink/callback', PaylinkReturnController::class)
            ->middleware([NoStore::class, 'auth'])->name('localized.payments.paylink.callback');
        Route::get('/payments/paylink/cancel', PaylinkReturnController::class)
            ->middleware([NoStore::class, 'auth'])->name('localized.payments.paylink.cancel');
        Route::get('/orders/{order}', OrderController::class)
            ->middleware(['auth', NoStore::class])->name('localized.store.orders.show');
        Route::post('/orders/{order:public_id}/payments/paylink', PaylinkOrderPaymentController::class)
            ->middleware(['auth', NoStore::class, 'throttle:coins-cart'])
            ->name('localized.store.orders.paylink-payment');
        Route::get('/cart/items/{cartItem}/credentials', [CartItemCredentialsController::class, 'show'])
            ->middleware([NoStore::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.credentials.show');
        Route::patch('/cart/items/{cartItem}/credentials', [CartItemCredentialsController::class, 'update'])
            ->middleware([NoStore::class, RequireCoinsCartJson::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.credentials.update');
        Route::delete('/cart/items/{cartItem}', [CartItemController::class, 'destroy'])
            ->middleware([NoStore::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.destroy');
        Route::get('/reviews', ReviewsController::class)->name('localized.store.reviews');
        Route::post('/cart/items/coins', CoinsCartController::class)
            ->middleware([NoStore::class, RequireCoinsCartJson::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.coins.store');
        Route::post('/cart/items/catalog', CatalogCartController::class)
            ->middleware([NoStore::class, RequireCatalogCartJson::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.catalog.store');
        Route::post('/cart/items/sbc', SbcCartController::class)
            ->middleware([NoStore::class, RequireCatalogCartJson::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.sbc.store');
        Route::post('/cart/items/fut-champions', FutChampionsCartController::class)
            ->middleware([NoStore::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.fut-champions.store');
        Route::post('/cart/items/rivals', RivalsCartController::class)
            ->middleware([NoStore::class, 'throttle:coins-cart'])
            ->name('localized.cart.items.rivals.store');
        Route::get('/auth/google/redirect', [GoogleAuthenticationController::class, 'redirect'])
            ->middleware(['guest:'.config('fortify.guard')])
            ->name('localized.auth.google.redirect');
        Route::get('/auth/google/callback', [GoogleAuthenticationController::class, 'callback'])
            ->middleware(['guest:'.config('fortify.guard')])
            ->name('localized.auth.google.callback');
        Route::post('/auth/whatsapp/code', [WhatsAppLoginController::class, 'send'])
            ->middleware(['guest:'.config('fortify.guard'), 'throttle:whatsapp-login-send'])
            ->name('localized.auth.whatsapp.send');
        Route::post('/auth/whatsapp/verify', [WhatsAppLoginController::class, 'verify'])
            ->middleware(['guest:'.config('fortify.guard'), 'throttle:whatsapp-login-verify'])
            ->name('localized.auth.whatsapp.verify');

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

        foreach ($manualServiceProducts as $name => [$uri, $service]) {
            Route::get($uri, ManualServiceProductController::class)
                ->defaults('service', $service)->name("localized.store.{$name}");
        }
    });

Route::get('dashboard', function (Request $request) {
    return redirect()->to($request->user()?->preferred_locale === 'en'
        ? route('localized.account.overview', absolute: false)
        : route('account.overview', absolute: false));
})->middleware('auth')->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/account.php';
require __DIR__.'/chat.php';
