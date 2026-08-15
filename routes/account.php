<?php

use App\Http\Controllers\Account\OverviewController;
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
});

Route::prefix('en')
    ->name('localized.')
    ->middleware($accountMiddleware)
    ->group(function (): void {
        Route::get('/my-account', OverviewController::class)
            ->defaults('locale', 'en')
            ->name('account.overview');
    });
