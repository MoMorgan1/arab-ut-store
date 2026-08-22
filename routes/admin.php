<?php

use App\Http\Controllers\Admin\CustomerDetailController;
use App\Http\Controllers\Admin\CustomersController;
use App\Http\Controllers\Admin\CustomerStatusController;
use App\Http\Controllers\Admin\OrderDetailController;
use App\Http\Controllers\Admin\OrderItemSecretRevealController;
use App\Http\Controllers\Admin\OrdersController;
use App\Http\Controllers\Admin\OrderTransitionController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PaylinkRefundController;
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

                $orders = Route::get('/orders', OrdersController::class)
                    ->middleware('can:orders.view')
                    ->name('orders');

                if ($locale !== null) {
                    $orders->defaults('locale', $locale);
                }

                $orderDetail = Route::get('/orders/{publicId}', OrderDetailController::class)
                    ->middleware('can:orders.view')
                    ->name('orders.show');

                if ($locale !== null) {
                    $orderDetail->defaults('locale', $locale);
                }

                $orderTransition = Route::post('/orders/{publicId}/transitions', OrderTransitionController::class)
                    ->name('orders.transitions.store');

                if ($locale !== null) {
                    $orderTransition->defaults('locale', $locale);
                }

                $reveal = Route::post('/api/orders/{publicId}/items/{itemPublicId}/reveal', OrderItemSecretRevealController::class)
                    ->middleware(['can:orders.view', 'can:order_credentials.view'])
                    ->name('orders.items.reveal');

                if ($locale !== null) {
                    $reveal->defaults('locale', $locale);
                }

                $refund = Route::post('/api/orders/{order:public_id}/refund', PaylinkRefundController::class)
                    ->middleware(['password.confirm', 'can:orders.refund', 'throttle:staff-payments'])
                    ->name('orders.paylink-refund');

                if ($locale !== null) {
                    $refund->defaults('locale', $locale);
                }

                $customers = Route::get('/customers', CustomersController::class)
                    ->middleware('can:customers.view')
                    ->name('customers');

                if ($locale !== null) {
                    $customers->defaults('locale', $locale);
                }

                $customerDetail = Route::get('/customers/{publicId}', CustomerDetailController::class)
                    ->middleware('can:customers.view')
                    ->name('customers.show');

                if ($locale !== null) {
                    $customerDetail->defaults('locale', $locale);
                }

                $customerStatus = Route::post('/api/customers/{publicId}/status', CustomerStatusController::class)
                    ->middleware(['password.confirm', 'can:customers.update_status'])
                    ->name('customers.status.store');

                if ($locale !== null) {
                    $customerStatus->defaults('locale', $locale);
                }
            });
        });
};

$registerAdminRoutes('admin', 'admin.', 'en');
$registerAdminRoutes('en/admin', 'localized.admin.', 'en');
