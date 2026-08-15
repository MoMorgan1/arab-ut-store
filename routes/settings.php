<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$legacyAccountUrl = static function (Request $request, string $page): string {
    $localized = $request->user()->preferred_locale === 'en';

    return route(
        $localized ? "localized.account.{$page}" : "account.{$page}",
        absolute: false,
    );
};

Route::middleware(['auth'])->group(function () use ($legacyAccountUrl) {
    Route::get('settings', fn (Request $request) => redirect()->to(
        $legacyAccountUrl($request, 'profile.show'),
    ));

    Route::get('settings/profile', fn (Request $request) => redirect()->to(
        $legacyAccountUrl($request, 'profile.show'),
    ))->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/security', fn (Request $request) => redirect()->to(
        $legacyAccountUrl($request, 'security.show'),
    ))->name('security.edit');

    Route::get('settings/appearance', fn (Request $request) => redirect()->to(
        $legacyAccountUrl($request, 'profile.show'),
    ))->name('appearance.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');
});
