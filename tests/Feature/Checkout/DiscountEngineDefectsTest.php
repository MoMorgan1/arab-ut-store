<?php

use App\Actions\Checkout\ApplyCoupon;
use App\Actions\Checkout\PlaceOrder;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Checkout\CouponRejected;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CouponTarget;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

afterEach(function (): void {
    Carbon::setTestNow();
});

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'UTC'));
});

/* ------------------------------------------------------------------ *
 * Helper Fixtures
 * ------------------------------------------------------------------ */

function makeShopper(array $overrides = []): User
{
    static $seq = 1000;
    $seq++;

    return User::factory()->create(array_merge([
        'phone' => '+9665'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
        'phone_verified_at' => now(),
    ], $overrides));
}

function makeProductWithVariant(
    ServiceType $serviceType = ServiceType::Sbc,
    int $priceHalalah = 10000,
    ?Category $category = null,
    array $productOverrides = [],
    array $variantOverrides = [],
): array {
    $product = Product::factory()->create(array_merge([
        'category_id' => $category?->id,
        'service_type' => $serviceType,
        'name_ar' => 'منتج تجريبي',
        'name_en' => 'Test Product',
        'is_visible' => true,
        'archived_at' => null,
    ], $productOverrides));

    $variant = ProductVariant::factory()->for($product)->create(array_merge([
        'service_type' => $serviceType,
        'platform' => Platform::PlayStation,
        'price_halalah' => $priceHalalah,
        'sale_price_halalah' => null,
        'price_version' => 1,
        'is_active' => true,
    ], $variantOverrides));

    return [$product, $variant];
}

function makeCartWithItem(User $user, ProductVariant $variant, int $quantity = 1, ?int $unitPriceHalalah = null): array
{
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);

    $unitPrice = $unitPriceHalalah ?? (int) $variant->price_halalah;
    $total = $unitPrice * $quantity;

    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
        'unit_price_halalah' => $unitPrice,
        'total_halalah' => $total,
        'configuration' => [
            'service_type' => $variant->service_type->value,
            'platform' => $variant->platform->value,
            'market' => 'console',
            'completion_count' => 1,
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => $variant->price_version,
        ],
    ]);

    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 3],
        'retained_until' => null,
        'deleted_at' => null,
    ]);
    $secret->encrypted_payload = [
        'ea_email' => "user-{$user->id}-item-{$item->id}@example.test",
        'ea_password' => 'secret-password',
        'backup_codes' => ['12345678', '23456789', '34567890'],
    ];
    $secret->save();

    return [$cart, $item];
}

function attachAdditionalItem(Cart $cart, ProductVariant $variant, int $quantity = 1, ?int $unitPriceHalalah = null): CartItem
{
    $unitPrice = $unitPriceHalalah ?? (int) $variant->price_halalah;
    $total = $unitPrice * $quantity;

    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
        'unit_price_halalah' => $unitPrice,
        'total_halalah' => $total,
        'configuration' => [
            'service_type' => $variant->service_type->value,
            'platform' => $variant->platform->value,
            'market' => 'console',
            'completion_count' => 1,
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => $variant->price_version,
        ],
    ]);

    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 3],
        'retained_until' => null,
        'deleted_at' => null,
    ]);
    $secret->encrypted_payload = [
        'ea_email' => "cart-{$cart->id}-item-{$item->id}@example.test",
        'ea_password' => 'secret-password',
        'backup_codes' => ['87654321', '98765432', '09876543'],
    ];
    $secret->save();

    return $item;
}

/* ------------------------------------------------------------------ *
 * Defect 1: Cart preview equals PlaceOrder charged discount
 * ------------------------------------------------------------------ */

test('defect 1: cart preview discount equals checkout charged discount on promotion-net base', function (): void {
    $user = makeShopper();
    [$product, $variant] = makeProductWithVariant(priceHalalah: 10_000);
    [$cart] = makeCartWithItem($user, $variant);

    // Promotion: 20% off -> promo discount = 2,000 halalah, promotion-net line = 8,000 halalah
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض 20%',
        'name_en' => '20% off promo',
        'scope' => Promotion::SCOPE_ALL,
        'discount_type' => 'percent',
        'value' => 20,
        'is_active' => true,
    ]);

    // Coupon: 10% off
    // Base must be promotion-net (8,000 halalah), so coupon discount must be 800 halalah (NOT 1,000 halalah)
    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'PROMO10',
        'discount_type' => 'percent',
        'value' => 10,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    // Apply coupon to cart
    $appliedInCart = app(ApplyCoupon::class)->apply($cart->refresh(), 'PROMO10', $user);

    expect($appliedInCart->discountHalalah)->toBe(800);

    // Checkout via PlaceOrder
    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'defect-1-checkout');
    $order = $checkout->order->fresh(['discounts', 'items']);

    expect($order->subtotal_halalah)->toBe(8_000)
        ->and($order->discount_halalah)->toBe(800)
        ->and($order->payment_halalah)->toBe(7_200)
        ->and($order->total_halalah)->toBe(7_200)
        ->and($appliedInCart->discountHalalah)->toBe($order->discount_halalah);
});

test('defect 1: coupon minimum_order evaluates against promotion-net base in both cart and checkout', function (): void {
    $user = makeShopper();
    [$product, $variant] = makeProductWithVariant(priceHalalah: 10_000);
    [$cart] = makeCartWithItem($user, $variant);

    // Promotion: 20% off -> net = 8,000 halalah
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض 20%',
        'name_en' => '20% off promo',
        'scope' => Promotion::SCOPE_ALL,
        'discount_type' => 'percent',
        'value' => 20,
        'is_active' => true,
    ]);

    // Coupon: minimum order = 9,000 halalah
    // Pre-promo = 10,000 halalah, but net is 8,000 halalah < 9,000. Must reject in cart!
    Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'MIN90',
        'discount_type' => 'fixed',
        'value' => 1000,
        'minimum_order_halalah' => 9000,
        'is_active' => true,
    ]);

    expect(fn () => app(ApplyCoupon::class)->apply($cart->refresh(), 'MIN90', $user))
        ->toThrow(CouponRejected::class);
});

/* ------------------------------------------------------------------ *
 * Defect 2: Unpaid/cancelled orders release coupon limits
 * ------------------------------------------------------------------ */

test('defect 2: cancelled order releases usage limit while pending payment holds reservation', function (): void {
    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'ONCEONLY',
        'discount_type' => 'fixed',
        'value' => 500,
        'usage_limit' => 1,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    $userA = makeShopper();
    [$productA, $variantA] = makeProductWithVariant(priceHalalah: 2500);
    [$cartA] = makeCartWithItem($userA, $variantA);
    app(ApplyCoupon::class)->apply($cartA, 'ONCEONLY', $userA);

    // User A places order -> creates PendingPayment order and redemption
    $checkoutA = app(PlaceOrder::class)->execute($userA, 'ar', 'defect-2-order-a');
    $orderA = $checkoutA->order;

    expect($orderA->status)->toBe(OrderStatus::PendingPayment)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1);

    // User B tries to apply the coupon while Order A is PendingPayment -> must be rejected
    $userB = makeShopper();
    [$productB, $variantB] = makeProductWithVariant(priceHalalah: 2500);
    [$cartB] = makeCartWithItem($userB, $variantB);

    expect(fn () => app(ApplyCoupon::class)->apply($cartB, 'ONCEONLY', $userB))
        ->toThrow(CouponRejected::class);

    // Order A is cancelled
    $orderA->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

    // Now User B CAN apply the coupon and check out successfully!
    $appliedB = app(ApplyCoupon::class)->apply($cartB->refresh(), 'ONCEONLY', $userB);
    expect($appliedB->discountHalalah)->toBe(500);

    $checkoutB = app(PlaceOrder::class)->execute($userB, 'ar', 'defect-2-order-b');
    expect($checkoutB->order->discount_halalah)->toBe(500)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(2);
});

test('defect 2: per_user_limit releases when customer cancelled their own previous order', function (): void {
    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'USERONCE',
        'discount_type' => 'fixed',
        'value' => 500,
        'per_user_limit' => 1,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    $user = makeShopper();
    [$product, $variant] = makeProductWithVariant(priceHalalah: 2500);
    [$cart1] = makeCartWithItem($user, $variant);

    app(ApplyCoupon::class)->apply($cart1, 'USERONCE', $user);
    $checkout1 = app(PlaceOrder::class)->execute($user, 'ar', 'defect-2-user-order-1');
    $order1 = $checkout1->order;

    // User creates a 2nd cart; coupon is blocked while order 1 is pending
    [$product2, $variant2] = makeProductWithVariant(priceHalalah: 2500);
    [$cart2] = makeCartWithItem($user, $variant2);

    expect(fn () => app(ApplyCoupon::class)->apply($cart2, 'USERONCE', $user))
        ->toThrow(CouponRejected::class);

    // Cancel order 1
    $order1->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

    // User can now apply again
    $applied2 = app(ApplyCoupon::class)->apply($cart2->refresh(), 'USERONCE', $user);
    expect($applied2->discountHalalah)->toBe(500);
});

/* ------------------------------------------------------------------ *
 * Defect 3: Paylink minimum checked after wallet split
 * ------------------------------------------------------------------ */

test('defect 3: 100 percent off coupon places order with payment_halalah = 0 and status Received', function (): void {
    $user = makeShopper();
    [$product, $variant] = makeProductWithVariant(priceHalalah: 2500);
    [$cart] = makeCartWithItem($user, $variant);

    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'FREE100',
        'discount_type' => 'percent',
        'value' => 100,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    app(ApplyCoupon::class)->apply($cart, 'FREE100', $user);

    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'defect-3-free-order');
    $order = $checkout->order->fresh(['payments', 'discounts']);

    expect($order->status)->toBe(OrderStatus::Received)
        ->and($order->subtotal_halalah)->toBe(2500)
        ->and($order->discount_halalah)->toBe(2500)
        ->and($order->payment_halalah)->toBe(0)
        ->and($order->total_halalah)->toBe(0)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->payments->sole()->provider)->toBe('wallet')
        ->and($order->payments->sole()->amount_halalah)->toBe(0);
});

test('defect 3: payable amount between 1 and 499 halalah is rejected with gap message', function (): void {
    $user = makeShopper();
    // Item 1000 halalah (>= 500 cart minimum), coupon 600 off -> remainder = 400 halalah (gap = 100 halalah)
    [$product, $variant] = makeProductWithVariant(priceHalalah: 1000);
    [$cart] = makeCartWithItem($user, $variant);

    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'OFF600',
        'discount_type' => 'fixed',
        'value' => 600,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    app(ApplyCoupon::class)->apply($cart, 'OFF600', $user);

    try {
        app(PlaceOrder::class)->execute($user, 'ar', 'defect-3-gap');
        test()->fail('Expected CheckoutUnavailable exception was not thrown.');
    } catch (CheckoutUnavailable $exception) {
        // The order is placed in Arabic, so the message must be the Arabic one
        // and must name the exact shortfall - the customer has to know how much
        // more to add.
        expect($exception->getMessage())->toBe((string) trans(
            'store.checkout.paylink_minimum_gap',
            ['gap' => '1.00'],
            locale: 'ar',
        ))->and($exception->getMessage())->toContain('1.00');
    }
});

test('defect 3: customer whose wallet covers the payable remainder checks out successfully', function (): void {
    $user = makeShopper();
    WalletAccount::query()->create([
        'public_id' => (string) Str::ulid(),
        'user_id' => $user->id,
        'balance_halalah' => 500,
    ]);

    [$product, $variant] = makeProductWithVariant(priceHalalah: 1000);
    [$cart] = makeCartWithItem($user, $variant);
    $cart->update(['use_wallet' => true]);

    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'OFF600',
        'discount_type' => 'fixed',
        'value' => 600,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    app(ApplyCoupon::class)->apply($cart, 'OFF600', $user);

    // Total = 400, Wallet covers 400 -> payment_halalah = 0 -> succeeds!
    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'defect-3-wallet-covered');
    $order = $checkout->order->fresh();

    expect($order->status)->toBe(OrderStatus::Received)
        ->and($order->subtotal_halalah)->toBe(1000)
        ->and($order->discount_halalah)->toBe(600)
        ->and($order->wallet_halalah)->toBe(400)
        ->and($order->payment_halalah)->toBe(0)
        ->and($order->total_halalah)->toBe(400);
});

/* ------------------------------------------------------------------ *
 * Coupon Targeting & Scope Tests
 * ------------------------------------------------------------------ */

test('coupon category scope restricts eligible base to matching category items only', function (): void {
    $user = makeShopper();
    $catA = Category::factory()->create();
    $catB = Category::factory()->create();

    [$prodA, $varA] = makeProductWithVariant(priceHalalah: 3000, category: $catA);
    [$prodB, $varB] = makeProductWithVariant(priceHalalah: 5000, category: $catB);

    [$cart, $itemA] = makeCartWithItem($user, $varA);
    $itemB = attachAdditionalItem($cart, $varB);

    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'CATA10',
        'discount_type' => 'percent',
        'value' => 10,
        'scope' => Coupon::SCOPE_CATEGORY,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);
    CouponTarget::query()->create([
        'coupon_id' => $coupon->id,
        'target_type' => CouponTarget::TYPE_CATEGORY,
        'target_id' => $catA->id,
    ]);

    $applied = app(ApplyCoupon::class)->apply($cart->refresh(), 'CATA10', $user);

    // Eligible base is only Cat A (3000 halalah), 10% = 300 halalah (NOT 800 halalah)
    expect($applied->discountHalalah)->toBe(300);

    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'cat-scope-order');
    $discount = $checkout->order->discounts->sole();

    expect($discount->amount_halalah)->toBe(300)
        ->and($discount->metadata['allocations'][0])->toBe(300)
        ->and($discount->metadata['allocations'][1])->toBe(0);
});

test('coupon product scope restricts eligible base to targeted product lines only', function (): void {
    $user = makeShopper();
    [$prodX, $varX] = makeProductWithVariant(priceHalalah: 4000);
    [$prodY, $varY] = makeProductWithVariant(priceHalalah: 6000);

    [$cart, $itemX] = makeCartWithItem($user, $varX);
    $itemY = attachAdditionalItem($cart, $varY);

    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'PRODXFIXED',
        'discount_type' => 'fixed',
        'value' => 1500,
        'scope' => Coupon::SCOPE_PRODUCT,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);
    CouponTarget::query()->create([
        'coupon_id' => $coupon->id,
        'target_type' => CouponTarget::TYPE_PRODUCT,
        'target_id' => $prodX->id,
    ]);

    $applied = app(ApplyCoupon::class)->apply($cart->refresh(), 'PRODXFIXED', $user);

    expect($applied->discountHalalah)->toBe(1500);

    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'prod-scope-order');
    $discount = $checkout->order->discounts->sole();

    expect($discount->metadata['allocations'][0])->toBe(1500)
        ->and($discount->metadata['allocations'][1])->toBe(0);
});

test('coupon service scope restricts eligible base to matching service type', function (): void {
    $user = makeShopper();
    [$prodCoins, $varCoins] = makeProductWithVariant(ServiceType::Coins, priceHalalah: 2000);
    [$prodSbc, $varSbc] = makeProductWithVariant(ServiceType::Sbc, priceHalalah: 4000);

    [$cart] = makeCartWithItem($user, $varCoins);
    attachAdditionalItem($cart, $varSbc);

    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'COINS20',
        'discount_type' => 'percent',
        'value' => 20,
        'scope' => Coupon::SCOPE_SERVICE,
        'service_type' => ServiceType::Coins->value,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    $applied = app(ApplyCoupon::class)->apply($cart->refresh(), 'COINS20', $user);

    // Eligible base is only Coins item (2000 halalah) -> 20% = 400 halalah
    expect($applied->discountHalalah)->toBe(400);
});

/* ------------------------------------------------------------------ *
 * excludes_promoted_items & first_order_only
 * ------------------------------------------------------------------ */

test('coupon with excludes_promoted_items excludes lines with active promotions from discount base', function (): void {
    $user = makeShopper();
    $catPromo = Category::factory()->create();
    $catNoPromo = Category::factory()->create();

    [$prodA, $varA] = makeProductWithVariant(priceHalalah: 5000, category: $catPromo);
    [$prodB, $varB] = makeProductWithVariant(priceHalalah: 5000, category: $catNoPromo);

    // Promotion only on CatPromo: 20% off -> line A net = 4000 halalah
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض فئة أ',
        'name_en' => 'Cat A promo',
        'scope' => Promotion::SCOPE_CATEGORY,
        'category_id' => $catPromo->id,
        'discount_type' => 'percent',
        'value' => 20,
        'is_active' => true,
    ]);

    [$cart] = makeCartWithItem($user, $varA);
    attachAdditionalItem($cart, $varB);

    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'NOPROMOS10',
        'discount_type' => 'percent',
        'value' => 10,
        'excludes_promoted_items' => true,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    $applied = app(ApplyCoupon::class)->apply($cart->refresh(), 'NOPROMOS10', $user);

    // Line A has promo discount, so it is excluded. Line B (5000 halalah) is eligible.
    // Coupon discount = 10% of 5000 = 500 halalah (NOT 10% of (4000 + 5000) = 900 halalah)
    expect($applied->discountHalalah)->toBe(500);

    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'excludes-promo-checkout');
    $order = $checkout->order->fresh(['discounts']);

    expect($order->discount_halalah)->toBe(500)
        ->and($order->discounts->sole()->metadata['allocations'][0])->toBe(0)
        ->and($order->discounts->sole()->metadata['allocations'][1])->toBe(500);
});

test('first_order_only allows customer with only pending orders and blocks customer with paid orders', function (): void {
    $user = makeShopper();
    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'WELCOMEFIRST',
        'discount_type' => 'fixed',
        'value' => 500,
        'first_order_only' => true,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    // User has an abandoned pending order (paid_at is NULL)
    Order::query()->create([
        'public_id' => (string) Str::ulid(),
        'user_id' => $user->id,
        'order_number' => 'AR-PENDING-1',
        'status' => OrderStatus::PendingPayment,
        'locale' => 'ar',
        'currency' => 'SAR',
        'subtotal_halalah' => 2000,
        'total_halalah' => 2000,
        'placed_at' => now()->subDay(),
        'paid_at' => null,
    ]);

    [$product, $variant] = makeProductWithVariant(priceHalalah: 2500);
    [$cart] = makeCartWithItem($user, $variant);

    // First-order coupon MUST succeed because the user has no paid order!
    $applied = app(ApplyCoupon::class)->apply($cart, 'WELCOMEFIRST', $user);
    expect($applied->discountHalalah)->toBe(500);

    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'first-order-success');
    expect($checkout->order->discount_halalah)->toBe(500);

    // Now mark the order as paid
    $checkout->order->update(['paid_at' => now(), 'status' => OrderStatus::Completed]);

    // Customer creates a subsequent cart
    [$product2, $variant2] = makeProductWithVariant(priceHalalah: 2500);
    [$cart2] = makeCartWithItem($user, $variant2);

    // Now first-order coupon MUST be rejected!
    expect(fn () => app(ApplyCoupon::class)->apply($cart2, 'WELCOMEFIRST', $user))
        ->toThrow(CouponRejected::class);
});

/* ------------------------------------------------------------------ *
 * Largest-Remainder Allocation & Non-stacking
 * ------------------------------------------------------------------ */

test('largest-remainder allocation sums exactly to the coupon total across 3 lines with uneven division', function (): void {
    $user = makeShopper();
    [$prod1, $var1] = makeProductWithVariant(priceHalalah: 1000);
    [$prod2, $var2] = makeProductWithVariant(priceHalalah: 1000);
    [$prod3, $var3] = makeProductWithVariant(priceHalalah: 1000);

    [$cart] = makeCartWithItem($user, $var1);
    attachAdditionalItem($cart, $var2);
    attachAdditionalItem($cart, $var3);

    // Total base = 3000. Fixed discount = 100 halalah.
    // Exact shares: 33.333... per line.
    // Largest remainder must give: 34 + 33 + 33 = 100 halalah exactly with no drift.
    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'FLAT100',
        'discount_type' => 'fixed',
        'value' => 100,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    $applied = app(ApplyCoupon::class)->apply($cart->refresh(), 'FLAT100', $user);
    expect($applied->discountHalalah)->toBe(100);

    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'largest-remainder-order');
    $discount = $checkout->order->discounts->sole();

    $allocations = $discount->metadata['allocations'];
    expect(array_sum($allocations))->toBe(100)
        ->and($allocations[0] + $allocations[1] + $allocations[2])->toBe(100);
});

test('promotions never stack: single best promotion wins per item', function (): void {
    $cat = Category::factory()->create();
    [$product, $variant] = makeProductWithVariant(priceHalalah: 10_000, category: $cat);

    // 3 overlapping promotions on the same item:
    // 1. All-scope: 10% (1,000 halalah)
    // 2. Category-scope: 25% (2,500 halalah)
    // 3. Product-scope: 15% (1,500 halalah)
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض عام 10%',
        'name_en' => 'All 10%',
        'scope' => Promotion::SCOPE_ALL,
        'discount_type' => 'percent',
        'value' => 10,
        'is_active' => true,
    ]);
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض فئة 25%',
        'name_en' => 'Category 25%',
        'scope' => Promotion::SCOPE_CATEGORY,
        'category_id' => $cat->id,
        'discount_type' => 'percent',
        'value' => 25,
        'is_active' => true,
    ]);
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض منتج 15%',
        'name_en' => 'Product 15%',
        'scope' => Promotion::SCOPE_PRODUCT,
        'product_id' => $product->id,
        'discount_type' => 'percent',
        'value' => 15,
        'is_active' => true,
    ]);

    $user = makeShopper();
    [$cart] = makeCartWithItem($user, $variant);

    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'promo-no-stack-order');
    $order = $checkout->order->fresh(['items']);

    // Best promotion is 25% (2,500 halalah). They do NOT stack to 50% (5,000 halalah).
    expect($order->subtotal_halalah)->toBe(7_500)
        ->and($order->items->sole()->promotion_discount_halalah)->toBe(2_500)
        ->and($order->items->sole()->total_halalah)->toBe(7_500);
});
