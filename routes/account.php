<?php

use App\Http\Controllers\Account\LiveOrderController;
use App\Http\Controllers\Account\OrdersController;
use App\Http\Controllers\Account\OverviewController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\ProfileEmailController;
use App\Http\Controllers\Account\ProfilePhoneController;
use App\Http\Controllers\Account\WalletController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureMyAccountEnabled;
use App\Http\Middleware\NoStore;
use Illuminate\Support\Facades\Route;

$accountMiddleware = [
    EnsureMyAccountEnabled::class,
    'auth',
    EnsureActiveUser::class,
    NoStore::class,
    'inertia.encrypt',
];

Route::middleware($accountMiddleware)->group(function (): void {
    Route::get('/my-account', OverviewController::class)->name('account.overview');
    Route::get('/my-account/orders', OrdersController::class)->name('account.orders');
    Route::get('/my-account/orders/{order}', LiveOrderController::class)
        ->whereUlid('order')
        ->name('account.orders.show');
    Route::get('/my-account/wallet', WalletController::class)->name('account.wallet');
    Route::get('/my-account/profile', [ProfileController::class, 'show'])->name('account.profile.show');
    Route::patch('/my-account/profile', [ProfileController::class, 'update'])->name('account.profile.update');
    Route::post('/my-account/profile/email', [ProfileEmailController::class, 'store'])
        ->middleware('throttle:account-identity-send')
        ->name('account.profile.email.request');
    Route::get('/my-account/profile/email/{change}', [ProfileEmailController::class, 'confirm'])
        ->middleware('signed')
        ->whereUlid('change')
        ->name('account.profile.email.confirm');
    Route::post('/my-account/profile/phone', [ProfilePhoneController::class, 'store'])
        ->middleware('throttle:account-identity-send')
        ->name('account.profile.phone.request');
    Route::post('/my-account/profile/phone/confirm', [ProfilePhoneController::class, 'confirm'])
        ->middleware('throttle:account-identity-confirm')
        ->name('account.profile.phone.confirm');
});

Route::prefix('en')
    ->name('localized.')
    ->middleware($accountMiddleware)
    ->group(function (): void {
        Route::get('/my-account', OverviewController::class)
            ->defaults('locale', 'en')
            ->name('account.overview');
        Route::get('/my-account/orders', OrdersController::class)
            ->defaults('locale', 'en')
            ->name('account.orders');
        Route::get('/my-account/orders/{order}', LiveOrderController::class)
            ->whereUlid('order')
            ->defaults('locale', 'en')
            ->name('account.orders.show');
        Route::get('/my-account/wallet', WalletController::class)
            ->defaults('locale', 'en')
            ->name('account.wallet');
        Route::get('/my-account/profile', [ProfileController::class, 'show'])
            ->defaults('locale', 'en')
            ->name('account.profile.show');
        Route::patch('/my-account/profile', [ProfileController::class, 'update'])
            ->defaults('locale', 'en')
            ->name('account.profile.update');
        Route::post('/my-account/profile/email', [ProfileEmailController::class, 'store'])
            ->middleware('throttle:account-identity-send')
            ->defaults('locale', 'en')
            ->name('account.profile.email.request');
        Route::get('/my-account/profile/email/{change}', [ProfileEmailController::class, 'confirm'])
            ->middleware('signed')
            ->whereUlid('change')
            ->defaults('locale', 'en')
            ->name('account.profile.email.confirm');
        Route::post('/my-account/profile/phone', [ProfilePhoneController::class, 'store'])
            ->middleware('throttle:account-identity-send')
            ->defaults('locale', 'en')
            ->name('account.profile.phone.request');
        Route::post('/my-account/profile/phone/confirm', [ProfilePhoneController::class, 'confirm'])
            ->middleware('throttle:account-identity-confirm')
            ->defaults('locale', 'en')
            ->name('account.profile.phone.confirm');
    });
