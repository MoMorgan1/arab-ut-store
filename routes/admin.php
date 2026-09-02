<?php

use App\Http\Controllers\Admin\CategoriesController;
use App\Http\Controllers\Admin\CategoryVisibilityController;
use App\Http\Controllers\Admin\ConfirmPasswordController;
use App\Http\Controllers\Admin\ConfirmTwoFactorController;
use App\Http\Controllers\Admin\ConversationDetailController;
use App\Http\Controllers\Admin\ConversationNoteController;
use App\Http\Controllers\Admin\ConversationReplyController;
use App\Http\Controllers\Admin\ConversationsController;
use App\Http\Controllers\Admin\ConversationTakeOverController;
use App\Http\Controllers\Admin\CouponDetailController;
use App\Http\Controllers\Admin\CouponsController;
use App\Http\Controllers\Admin\CreateCouponController;
use App\Http\Controllers\Admin\CreatePromotionController;
use App\Http\Controllers\Admin\CustomerContactController;
use App\Http\Controllers\Admin\CustomerDetailController;
use App\Http\Controllers\Admin\CustomersController;
use App\Http\Controllers\Admin\CustomerStatusController;
use App\Http\Controllers\Admin\CustomerWalletAdjustController;
use App\Http\Controllers\Admin\DuplicateCouponController;
use App\Http\Controllers\Admin\LoyaltyController;
use App\Http\Controllers\Admin\LoyaltyTierController;
use App\Http\Controllers\Admin\MoreController;
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
use App\Http\Controllers\Admin\PromotionsController;
use App\Http\Controllers\Admin\ResolveTicketController;
use App\Http\Controllers\Admin\ReviewsController;
use App\Http\Controllers\Admin\ReviewVisibilityController;
use App\Http\Controllers\Admin\ServicePricingController;
use App\Http\Controllers\Admin\ServicePricingStatusController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SupportUnreadCountController;
use App\Http\Controllers\Admin\TeamGrantController;
use App\Http\Controllers\Admin\TeamRoleController;
use App\Http\Controllers\Admin\TeamStatusController;
use App\Http\Controllers\Admin\ToggleCouponStatusController;
use App\Http\Controllers\Admin\TogglePromotionStatusController;
use App\Http\Controllers\Admin\TrustedDeviceController;
use App\Http\Controllers\Admin\UpdateCouponController;
use App\Http\Controllers\Admin\UpdatePromotionController;
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
            Route::middleware([EnsureAdminPassword::class])
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

                    $confirmPassword = Route::get('/security/confirm-password', ConfirmPasswordController::class)
                        ->name('security.confirm-password');

                    if ($locale !== null) {
                        $confirmPassword->defaults('locale', $locale);
                    }
                });

            $confirmCreate = Route::get('/confirm-2fa', [ConfirmTwoFactorController::class, 'create'])
                ->name('confirm-2fa');

            if ($locale !== null) {
                $confirmCreate->defaults('locale', $locale);
            }

            $confirmStore = Route::post('/confirm-2fa', [ConfirmTwoFactorController::class, 'store'])
                ->middleware('throttle:two-factor-management')
                ->name('confirm-2fa.store');

            if ($locale !== null) {
                $confirmStore->defaults('locale', $locale);
            }

            Route::middleware(EnsureAdminMfa::class)->group(function () use ($locale): void {
                $route = Route::get('/', OverviewController::class)->name('overview');

                if ($locale !== null) {
                    $route->defaults('locale', $locale);
                }

                $more = Route::get('/more', MoreController::class)->name('more');

                if ($locale !== null) {
                    $more->defaults('locale', $locale);
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
                    ->middleware(['can:orders.refund', 'throttle:staff-payments'])
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
                    ->middleware('can:customers.update_status')
                    ->name('customers.status.store');

                if ($locale !== null) {
                    $customerStatus->defaults('locale', $locale);
                }

                $customerContact = Route::post('/api/customers/{publicId}/contact', CustomerContactController::class)
                    ->middleware(['can:customers.update_contact', 'throttle:staff-identity'])
                    ->name('customers.contact.store');

                if ($locale !== null) {
                    $customerContact->defaults('locale', $locale);
                }

                $customerWalletAdjust = Route::post('/api/customers/{publicId}/wallet/adjust', CustomerWalletAdjustController::class)
                    ->middleware('can:wallet.adjust')
                    ->name('customers.wallet.adjust');

                if ($locale !== null) {
                    $customerWalletAdjust->defaults('locale', $locale);
                }

                $loyalty = Route::get('/marketing/loyalty', LoyaltyController::class)
                    ->middleware('can:loyalty.view')
                    ->name('marketing.loyalty');

                if ($locale !== null) {
                    $loyalty->defaults('locale', $locale);
                }

                $loyaltyTierUpdate = Route::put('/api/marketing/loyalty/tiers/{publicId}', LoyaltyTierController::class)
                    ->middleware('can:loyalty.manage')
                    ->name('marketing.loyalty.tiers.update');

                if ($locale !== null) {
                    $loyaltyTierUpdate->defaults('locale', $locale);
                }

                $teamGrant = Route::post('/api/team/grants', TeamGrantController::class)
                    ->middleware(['can:staff.manage', 'throttle:staff-identity'])
                    ->name('team.grants.store');

                if ($locale !== null) {
                    $teamGrant->defaults('locale', $locale);
                }

                $conversations = Route::get('/conversations', ConversationsController::class)
                    ->middleware('can:chat.view')
                    ->name('conversations');

                if ($locale !== null) {
                    $conversations->defaults('locale', $locale);
                }

                $conversationDetail = Route::get('/conversations/{publicId}', ConversationDetailController::class)
                    ->middleware('can:chat.view')
                    ->name('conversations.show');

                if ($locale !== null) {
                    $conversationDetail->defaults('locale', $locale);
                }

                $supportUnreadCount = Route::get('/support/unread-count', SupportUnreadCountController::class)
                    ->middleware('can:chat.view')
                    ->name('support.unread-count');

                if ($locale !== null) {
                    $supportUnreadCount->defaults('locale', $locale);
                }

                $conversationReply = Route::post('/conversations/{publicId}/reply', ConversationReplyController::class)
                    ->middleware(['can:chat.reply', 'throttle:60,1'])
                    ->name('conversations.reply');

                if ($locale !== null) {
                    $conversationReply->defaults('locale', $locale);
                }

                $conversationNote = Route::post('/conversations/{publicId}/note', ConversationNoteController::class)
                    ->middleware('can:chat.reply')
                    ->name('conversations.note');

                if ($locale !== null) {
                    $conversationNote->defaults('locale', $locale);
                }

                $conversationTakeOver = Route::post('/conversations/{publicId}/take-over', ConversationTakeOverController::class)
                    ->middleware('can:chat.reply')
                    ->name('conversations.take-over');

                if ($locale !== null) {
                    $conversationTakeOver->defaults('locale', $locale);
                }

                $ticketResolve = Route::patch('/tickets/{publicId}', ResolveTicketController::class)
                    ->middleware('can:chat.reply')
                    ->name('tickets.resolve');

                if ($locale !== null) {
                    $ticketResolve->defaults('locale', $locale);
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
                    ->middleware('can:catalog.manage')
                    ->name('products.update');

                if ($locale !== null) {
                    $product->defaults('locale', $locale);
                }

                $productVisibility = Route::post('/api/products/{publicId}/visibility', ProductVisibilityController::class)
                    ->middleware('can:catalog.manage')
                    ->name('products.visibility.store');

                if ($locale !== null) {
                    $productVisibility->defaults('locale', $locale);
                }

                $variantPrice = Route::post('/api/variants/{publicId}/price', VariantPriceController::class)
                    ->middleware(['can:catalog.manage', 'throttle:staff-payments'])
                    ->name('variants.price.store');

                if ($locale !== null) {
                    $variantPrice->defaults('locale', $locale);
                }

                $categories = Route::get('/categories', CategoriesController::class)
                    ->middleware('can:catalog.view')
                    ->name('categories');

                if ($locale !== null) {
                    $categories->defaults('locale', $locale);
                }

                $categoryVisibility = Route::post('/api/categories/{publicId}/visibility', CategoryVisibilityController::class)
                    ->middleware('can:catalog.manage')
                    ->name('categories.visibility.store');

                if ($locale !== null) {
                    $categoryVisibility->defaults('locale', $locale);
                }

                $teamRole = Route::post('/api/team/{publicId}/role', TeamRoleController::class)
                    ->middleware('can:staff.manage')
                    ->name('team.role.store');

                if ($locale !== null) {
                    $teamRole->defaults('locale', $locale);
                }

                $teamStatus = Route::post('/api/team/{publicId}/status', TeamStatusController::class)
                    ->middleware('can:staff.manage')
                    ->name('team.status.store');

                if ($locale !== null) {
                    $teamStatus->defaults('locale', $locale);
                }

                $coupons = Route::get('/marketing/coupons', CouponsController::class)
                    ->middleware('can:marketing.view')
                    ->name('marketing.coupons');

                if ($locale !== null) {
                    $coupons->defaults('locale', $locale);
                }

                $couponDetail = Route::get('/marketing/coupons/{publicId}', CouponDetailController::class)
                    ->middleware('can:marketing.view')
                    ->name('marketing.coupons.show');

                if ($locale !== null) {
                    $couponDetail->defaults('locale', $locale);
                }

                $createCoupon = Route::post('/api/marketing/coupons', CreateCouponController::class)
                    ->middleware('can:marketing.manage')
                    ->name('marketing.coupons.store');

                if ($locale !== null) {
                    $createCoupon->defaults('locale', $locale);
                }

                $updateCoupon = Route::put('/api/marketing/coupons/{publicId}', UpdateCouponController::class)
                    ->middleware('can:marketing.manage')
                    ->name('marketing.coupons.update');

                if ($locale !== null) {
                    $updateCoupon->defaults('locale', $locale);
                }

                $toggleCoupon = Route::post('/api/marketing/coupons/{publicId}/status', ToggleCouponStatusController::class)
                    ->middleware('can:marketing.manage')
                    ->name('marketing.coupons.status.store');

                if ($locale !== null) {
                    $toggleCoupon->defaults('locale', $locale);
                }

                $duplicateCoupon = Route::post('/api/marketing/coupons/{publicId}/duplicate', DuplicateCouponController::class)
                    ->middleware('can:marketing.manage')
                    ->name('marketing.coupons.duplicate');

                if ($locale !== null) {
                    $duplicateCoupon->defaults('locale', $locale);
                }

                $reviews = Route::get('/reviews', ReviewsController::class)
                    ->middleware('can:marketing.view')
                    ->name('reviews');

                if ($locale !== null) {
                    $reviews->defaults('locale', $locale);
                }

                $reviewVisibility = Route::post('/api/reviews/{publicId}/visibility', ReviewVisibilityController::class)
                    ->middleware('can:marketing.manage')
                    ->name('reviews.visibility.store');

                if ($locale !== null) {
                    $reviewVisibility->defaults('locale', $locale);
                }

                $promotions = Route::get('/marketing/promotions', PromotionsController::class)
                    ->middleware('can:marketing.view')
                    ->name('marketing.promotions');

                if ($locale !== null) {
                    $promotions->defaults('locale', $locale);
                }

                $createPromotion = Route::post('/api/marketing/promotions', CreatePromotionController::class)
                    ->middleware('can:marketing.manage')
                    ->name('marketing.promotions.store');

                if ($locale !== null) {
                    $createPromotion->defaults('locale', $locale);
                }

                $updatePromotion = Route::put('/api/marketing/promotions/{publicId}', UpdatePromotionController::class)
                    ->middleware('can:marketing.manage')
                    ->name('marketing.promotions.update');

                if ($locale !== null) {
                    $updatePromotion->defaults('locale', $locale);
                }

                $togglePromotion = Route::post('/api/marketing/promotions/{publicId}/status', TogglePromotionStatusController::class)
                    ->middleware('can:marketing.manage')
                    ->name('marketing.promotions.status.store');

                if ($locale !== null) {
                    $togglePromotion->defaults('locale', $locale);
                }

                $servicePricing = Route::post('/api/settings/service-pricing/{serviceType}', ServicePricingController::class)
                    ->middleware(['can:settings.manage', 'throttle:staff-identity'])
                    ->name('settings.service-pricing.store');

                if ($locale !== null) {
                    $servicePricing->defaults('locale', $locale);
                }

                $servicePricingStatus = Route::post('/api/settings/service-pricing/{serviceType}/status', ServicePricingStatusController::class)
                    ->middleware(['can:settings.manage', 'throttle:staff-identity'])
                    ->name('settings.service-pricing.status.store');

                if ($locale !== null) {
                    $servicePricingStatus->defaults('locale', $locale);
                }

                $trustedDevices = Route::delete('/api/security/trusted-devices', TrustedDeviceController::class)
                    ->middleware('throttle:two-factor-management')
                    ->name('security.trusted-devices.destroy');

                if ($locale !== null) {
                    $trustedDevices->defaults('locale', $locale);
                }
            });
        });
};

$registerAdminRoutes('admin', 'admin.', 'en');
$registerAdminRoutes('en/admin', 'localized.admin.', 'en');
