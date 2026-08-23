<?php

use App\Http\Controllers\Admin\CustomerContactController;
use App\Http\Controllers\Admin\CustomerDetailController;
use App\Http\Controllers\Admin\CustomersController;
use App\Http\Controllers\Admin\CustomerStatusController;
use App\Http\Controllers\Admin\OrderDetailController;
use App\Http\Controllers\Admin\OrderItemSecretRevealController;
use App\Http\Controllers\Admin\OrdersController;
use App\Http\Controllers\Admin\OrderTransitionController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PaylinkRefundController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductDetailController;
use App\Http\Controllers\Admin\ProductsController;
use App\Http\Controllers\Admin\ProductVisibilityController;
use App\Http\Controllers\Admin\ServicePricingController;
use App\Http\Controllers\Admin\ServicePricingStatusController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TeamGrantController;
use App\Http\Controllers\Admin\TeamRoleController;
use App\Http\Controllers\Admin\TeamStatusController;
use App\Http\Controllers\Admin\TrustedDeviceController;
use App\Http\Controllers\Admin\VariantPriceController;
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
        ->group(function () use ($locale, $name): void {
            Route::middleware([EnsureAdminPassword::class, 'password.confirm'])
                ->group(function () use ($locale, $name): void {
                    $settings = Route::get('/settings', SettingsController::class)
                        ->name('settings');

                    if ($locale !== null) {
                        $settings->defaults('locale', $locale);
                    }

                    $mfa = Route::get('/security/mfa', function () use ($name) {
                        return redirect()->route($name.'settings', status: 301);
                    })->name('security.mfa');

                    if ($locale !== null) {
                        $mfa->defaults('locale', $locale);
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

                $customerContact = Route::post('/api/customers/{publicId}/contact', CustomerContactController::class)
                    ->middleware(['password.confirm', 'can:customers.update_contact', 'throttle:staff-identity'])
                    ->name('customers.contact.store');

                if ($locale !== null) {
                    $customerContact->defaults('locale', $locale);
                }

                $teamGrant = Route::post('/api/team/grants', TeamGrantController::class)
                    ->middleware(['password.confirm', 'can:staff.manage', 'throttle:staff-identity'])
                    ->name('team.grants.store');

                if ($locale !== null) {
                    $teamGrant->defaults('locale', $locale);
                }

                $products = Route::get('/products', ProductsController::class)
                    ->middleware('can:catalog.view')
                    ->name('products');

                if ($locale !== null) {
                    $products->defaults('locale', $locale);
                }

                $productDetail = Route::get('/products/{publicId}', ProductDetailController::class)
                    ->middleware('can:catalog.view')
                    ->name('products.show');

                if ($locale !== null) {
                    $productDetail->defaults('locale', $locale);
                }

                $product = Route::post('/api/products/{publicId}', ProductController::class)
                    ->middleware(['password.confirm', 'can:catalog.manage'])
                    ->name('products.update');

                if ($locale !== null) {
                    $product->defaults('locale', $locale);
                }

                $productVisibility = Route::post('/api/products/{publicId}/visibility', ProductVisibilityController::class)
                    ->middleware(['password.confirm', 'can:catalog.manage'])
                    ->name('products.visibility.store');

                if ($locale !== null) {
                    $productVisibility->defaults('locale', $locale);
                }

                $variantPrice = Route::post('/api/variants/{publicId}/price', VariantPriceController::class)
                    ->middleware(['password.confirm', 'can:catalog.manage', 'throttle:staff-payments'])
                    ->name('variants.price.store');

                if ($locale !== null) {
                    $variantPrice->defaults('locale', $locale);
                }

                $teamRole = Route::post('/api/team/{publicId}/role', TeamRoleController::class)
                    ->middleware(['password.confirm', 'can:staff.manage'])
                    ->name('team.role.store');

                if ($locale !== null) {
                    $teamRole->defaults('locale', $locale);
                }

                $teamStatus = Route::post('/api/team/{publicId}/status', TeamStatusController::class)
                    ->middleware(['password.confirm', 'can:staff.manage'])
                    ->name('team.status.store');

                if ($locale !== null) {
                    $teamStatus->defaults('locale', $locale);
                }

                $servicePricing = Route::post('/api/settings/service-pricing/{serviceType}', ServicePricingController::class)
                    ->middleware(['password.confirm', 'can:settings.manage', 'throttle:staff-identity'])
                    ->name('settings.service-pricing.store');

                if ($locale !== null) {
                    $servicePricing->defaults('locale', $locale);
                }

                $servicePricingStatus = Route::post('/api/settings/service-pricing/{serviceType}/status', ServicePricingStatusController::class)
                    ->middleware(['password.confirm', 'can:settings.manage', 'throttle:staff-identity'])
                    ->name('settings.service-pricing.status.store');

                if ($locale !== null) {
                    $servicePricingStatus->defaults('locale', $locale);
                }

                $trustedDevices = Route::delete('/api/security/trusted-devices', TrustedDeviceController::class)
                    ->middleware(['password.confirm', 'throttle:two-factor-management'])
                    ->name('security.trusted-devices.destroy');

                if ($locale !== null) {
                    $trustedDevices->defaults('locale', $locale);
                }
            });
        });
};

$registerAdminRoutes('admin', 'admin.', 'en');
$registerAdminRoutes('en/admin', 'localized.admin.', 'en');
