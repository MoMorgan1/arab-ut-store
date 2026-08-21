<?php

use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\Security\AdminMfaController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureAdminMfa;
use App\Http\Middleware\EnsureAdminPassword;
use App\Http\Middleware\PrivateNoStore;
use Illuminate\Support\Facades\Route;

$adminMiddleware = [
    'auth',
    EnsureActiveUser::class,
    EnsureAdminAccess::class,
    PrivateNoStore::class,
    'inertia.encrypt',
];

$registerAdminRoutes = function (string $prefix, string $name, ?string $locale = null) use ($adminMiddleware): void {
    Route::prefix($prefix)
        ->name($name)
        ->middleware($adminMiddleware)
        ->group(function () use ($locale): void {
            Route::middleware([EnsureAdminPassword::class, 'password.confirm'])
                ->group(function () use ($locale): void {
                    $route = Route::get('/security/mfa', [AdminMfaController::class, '__invoke'])
                        ->name('security.mfa');

                    if ($locale !== null) {
                        $route->defaults('locale', $locale);
                    }
                });

            Route::middleware(EnsureAdminMfa::class)->group(function () use ($locale): void {
                $route = Route::get('/', OverviewController::class)->name('overview');

                if ($locale !== null) {
                    $route->defaults('locale', $locale);
                }
            });
        });
};

$registerAdminRoutes('admin', 'admin.');
$registerAdminRoutes('en/admin', 'localized.admin.', 'en');
