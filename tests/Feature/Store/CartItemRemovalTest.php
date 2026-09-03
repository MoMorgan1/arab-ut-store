<?php

use App\Actions\Cart\PurgeRemovedCartItems;
use App\Actions\Cart\ResolveCartOwner;
use App\Actions\Checkout\PlaceOrder;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Security\CoinsCartFingerprint;
use App\Security\SbcCartFingerprint;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{product: Product, variants: array<string, ProductVariant>} */
function removalCoinsCatalog(): array
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'name_ar' => 'عملات ألتيميت تيم',
        'name_en' => 'Ultimate Team Coins',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variants = [];

    foreach (Platform::cases() as $platform) {
        $variants[$platform->value] = ProductVariant::factory()->for($product)->create([
            'service_type' => ServiceType::Coins,
            'platform' => $platform,
            'price_version' => 11,
            'is_active' => true,
        ]);
    }

    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        $configuration = [
            'version' => 1,
            'group' => $group,
            'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
            'multipliers_basis_points' => ['50000' => 10_000],
            'service_fee_halalah' => 0,
            'discount_divisor_basis_points' => 10_000,
            'exact_overrides_halalah' => [],
        ];
        $configuration[$group === 'console_normal'
            ? 'flat_rate_halalah_per_million'
            : 'tier_rates_halalah_per_million'] = $group === 'console_normal'
                ? 5_000
                : array_fill(0, 6, 5_000);

        PriceRule::create([
            'name' => "Removal Coins {$group}",
            'service_type' => ServiceType::Coins,
            'configuration' => $configuration,
            'is_active' => true,
        ]);
    }

    return ['product' => $product, 'variants' => $variants];
}

/** @return array<string, mixed> */
function removalCoinsPayload(array $changes = []): array
{
    $payload = [
        'platform' => 'playstation',
        'delivery' => 'normal',
        'quantity' => 100_000,
        'credentials' => [
            'ea_email' => 'removal-sentinel@example.test',
            'ea_password' => 'Opaque Removal Password',
            'backup_codes' => ['81000001', '81000002', '81000003'],
            'companion_market_open' => true,
            'policy_accepted' => true,
        ],
    ];
    $credentialChanges = $changes['credentials'] ?? [];
    unset($changes['credentials']);
    $payload = array_replace($payload, $changes);

    if (is_array($credentialChanges)) {
        $payload['credentials'] = array_replace($payload['credentials'], $credentialChanges);
    }

    return $payload;
}

/** @return array{product: Product, variant: ProductVariant} */
function removalSbcProduct(): array
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'name_ar' => 'تحدي بناء تشكيلة',
        'name_en' => 'Squad Building Challenge',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => 12_500,
        'sale_price_halalah' => null,
        'price_version' => 4,
        'is_active' => true,
    ]);

    return compact('product', 'variant');
}

/** @return array<string, mixed> */
function removalSbcPayload(ProductVariant $variant, array $changes = []): array
{
    $payload = [
        'variantId' => $variant->public_id,
        'completionCount' => 1,
        'credentials' => [
            'ea_email' => 'removal-sbc@example.test',
            'ea_password' => 'Opaque Removal SBC Password',
            'backup_codes' => ['93000001', '93000002', '93000003'],
        ],
    ];
    $credentialChanges = $changes['credentials'] ?? [];
    unset($changes['credentials']);
    $payload = array_replace($payload, $changes);

    if (is_array($credentialChanges)) {
        $payload['credentials'] = array_replace($payload['credentials'], $credentialChanges);
    }

    return $payload;
}

/** @return array{user: User, cart: Cart, item: CartItem} */
function removalCheckoutCart(): array
{
    $user = User::factory()->create([
        'phone' => '+966500000001',
        'phone_verified_at' => now(),
    ]);
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'name_ar' => 'تحدي لاعب',
        'name_en' => 'Player challenge',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => 1250,
        'sale_price_halalah' => null,
        'price_version' => 4,
        'is_active' => true,
    ]);
    $cart = Cart::create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 1250,
        'total_halalah' => 1250,
        'configuration' => [
            'service_type' => 'sbc',
            'platform' => 'playstation',
            'market' => 'console',
            'completion_count' => 1,
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => 4,
        ],
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 3],
        'retained_until' => null,
        'deleted_at' => null,
    ]);
    $secret->encrypted_payload = [
        'ea_email' => 'owner@example.test',
        'ea_password' => 'Opaque password',
        'backup_codes' => ['12345678', '23456789', '34567890'],
    ];
    $secret->save();

    return compact('user', 'cart', 'item');
}

test('removing a line soft-removes it and the cart page no longer shows it', function () {
    removalCoinsCatalog();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('a', 64)]);

    $this->postJson('/cart/items/coins', removalCoinsPayload(), ['Idempotency-Key' => 'soft-remove-key'])
        ->assertCreated();
    $item = CartItem::sole();

    $response = $this->deleteJson("/cart/items/{$item->public_id}");

    $response->assertOk()->assertJsonPath('data.cartCount', 0);
    expect($response->json('data.restoreUrl'))->toBe("/cart/items/{$item->public_id}/restore");

    // The scope hides the row from every existing reader; the row and its
    // secret stay for the undo window.
    expect(CartItem::count())->toBe(0)
        ->and(CartItem::withRemoved()->count())->toBe(1)
        ->and(CartItem::withRemoved()->sole()->removed_at)->not->toBeNull()
        ->and(CartItemSecret::count())->toBe(1);

    $this->get('/cart')->assertInertia(fn (Assert $page) => $page
        ->where('cart.count', 0)
        ->where('cart.items', []));
});

test('restoring within the window brings the line back with its credentials intact', function () {
    removalCoinsCatalog();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('b', 64)]);

    $this->postJson('/cart/items/coins', removalCoinsPayload(), ['Idempotency-Key' => 'restore-window-key'])
        ->assertCreated();
    $item = CartItem::sole();
    $restoreUrl = $this->deleteJson("/cart/items/{$item->public_id}")->json('data.restoreUrl');

    $this->postJson($restoreUrl)->assertOk()->assertJsonPath('data.cartCount', 1);

    expect(CartItem::count())->toBe(1)
        ->and(CartItem::sole()->removed_at)->toBeNull();

    $this->getJson("/cart/items/{$item->public_id}/credentials")
        ->assertOk()
        ->assertJsonPath('data.eaEmail', 'removal-sentinel@example.test');
});

test('restoring after thirty minutes is a 404', function () {
    removalCoinsCatalog();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('c', 64)]);

    $this->postJson('/cart/items/coins', removalCoinsPayload(), ['Idempotency-Key' => 'restore-expired-key'])
        ->assertCreated();
    $item = CartItem::sole();
    $restoreUrl = $this->deleteJson("/cart/items/{$item->public_id}")->json('data.restoreUrl');

    $item->update(['removed_at' => now()->subMinutes(31)]);

    $this->postJson($restoreUrl)->assertNotFound();
    expect(CartItem::count())->toBe(0);
});

test('restoring an unknown line or another owner line is a 404', function () {
    removalCoinsCatalog();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('d', 64)]);

    $this->postJson('/cart/items/coins', removalCoinsPayload(), ['Idempotency-Key' => 'restore-foreign-key'])
        ->assertCreated();
    $item = CartItem::sole();
    $restoreUrl = $this->deleteJson("/cart/items/{$item->public_id}")->json('data.restoreUrl');

    $this->flushSession();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('e', 64)]);
    $this->postJson($restoreUrl)->assertNotFound();
    $this->postJson('/cart/items/01K00000000000000000000000/restore')->assertNotFound();
});

test('restoring when the same variant was re-added is a 409', function () {
    removalCoinsCatalog();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('f', 64)]);

    $this->postJson('/cart/items/coins', removalCoinsPayload(), ['Idempotency-Key' => 'restore-first-key'])
        ->assertCreated();
    $item = CartItem::sole();
    $restoreUrl = $this->deleteJson("/cart/items/{$item->public_id}")->json('data.restoreUrl');

    // The variant is free again after the soft removal, so this lands.
    $this->postJson('/cart/items/coins', removalCoinsPayload(), ['Idempotency-Key' => 'restore-second-key'])
        ->assertCreated();

    $this->postJson($restoreUrl)
        ->assertConflict()
        ->assertJsonPath('error.code', 'already_in_cart');
    expect(CartItem::count())->toBe(1);
});

test('the purge hard-deletes expired removals with their secrets', function () {
    removalCoinsCatalog();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('g', 64)]);

    $this->postJson('/cart/items/coins', removalCoinsPayload(), ['Idempotency-Key' => 'purge-old-key'])
        ->assertCreated();
    $old = CartItem::sole();
    $this->deleteJson("/cart/items/{$old->public_id}")->assertOk();
    $old->refresh();
    $old->update(['removed_at' => now()->subMinutes(31)]);

    $this->postJson('/cart/items/coins', removalCoinsPayload(), ['Idempotency-Key' => 'purge-fresh-key'])
        ->assertCreated();
    $fresh = CartItem::query()->where('public_id', '!=', $old->public_id)->sole();
    $this->deleteJson("/cart/items/{$fresh->public_id}")->assertOk();

    $purged = app(PurgeRemovedCartItems::class)->execute();

    expect($purged)->toBe(1)
        ->and(CartItem::withRemoved()->where('public_id', $old->public_id)->count())->toBe(0)
        ->and(CartItem::withRemoved()->where('public_id', $fresh->public_id)->count())->toBe(1)
        ->and(CartItemSecret::count())->toBe(1);

    $this->artisan('cart-items:purge-removed')->assertSuccessful();
});

test('checkout ignores removed rows', function () {
    ['user' => $user, 'cart' => $cart, 'item' => $item] = removalCheckoutCart();

    $removed = $cart->items()->create([
        'product_variant_id' => $item->product_variant_id,
        'quantity' => 1,
        'unit_price_halalah' => 1250,
        'total_halalah' => 1250,
        'configuration' => $item->configuration,
        'removed_at' => now(),
    ]);

    $result = app(PlaceOrder::class)->execute($user, 'ar', 'checkout-ignores-removed');

    expect($result->order->items)->toHaveCount(1)
        ->and($result->order->total_halalah)->toBe(1250)
        ->and($removed->fresh()->removed_at)->not->toBeNull();
});

test('replacing a coins line removes the old line and creates the new one', function () {
    removalCoinsCatalog();
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->postJson('/cart/items/coins', removalCoinsPayload(), ['Idempotency-Key' => 'coins-replace-first'])
        ->assertCreated();
    $old = CartItem::sole();

    $this->postJson('/cart/items/coins', [
        ...removalCoinsPayload(['quantity' => 110_000]),
        'replaceCartItemId' => $old->public_id,
    ], ['Idempotency-Key' => 'coins-replace-second'])
        ->assertCreated()
        ->assertJsonPath('data.cartCount', 1);

    expect(CartItem::count())->toBe(1)
        ->and(CartItem::withRemoved()->count())->toBe(2)
        ->and($old->fresh()->removed_at)->not->toBeNull()
        ->and(CartItem::sole()->configuration['coins_quantity'])->toBe(110_000);
});

test('replacing a coins line that is not on the owner cart is refused', function () {
    removalCoinsCatalog();
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $this->postJson('/cart/items/coins', removalCoinsPayload(), ['Idempotency-Key' => 'coins-replace-owner'])
        ->assertCreated();
    $foreign = CartItem::sole();

    $this->actingAs(User::factory()->create());

    $this->postJson('/cart/items/coins', [
        ...removalCoinsPayload(),
        'replaceCartItemId' => $foreign->public_id,
    ], ['Idempotency-Key' => 'coins-replace-foreign'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'replaced_item_unavailable');
    expect(CartItem::withRemoved()->count())->toBe(1)
        ->and($foreign->fresh()->removed_at)->toBeNull();
});

test('replacing an SBC line removes the old line and keeps the count', function () {
    ['variant' => $variant] = removalSbcProduct();
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->postJson('/cart/items/sbc', removalSbcPayload($variant), ['Idempotency-Key' => 'sbc-replace-first'])
        ->assertCreated();
    $old = CartItem::sole();

    $this->postJson('/cart/items/sbc', [
        ...removalSbcPayload($variant),
        'replaceCartItemId' => $old->public_id,
    ], ['Idempotency-Key' => 'sbc-replace-second'])
        ->assertCreated()
        ->assertJsonPath('data.cartCount', 1);

    expect(CartItem::count())->toBe(1)
        ->and(CartItem::withRemoved()->count())->toBe(2)
        ->and($old->fresh()->removed_at)->not->toBeNull();
});

test('the cart fingerprints include the replaced line id', function () {
    $coins = [
        'platform' => 'playstation',
        'delivery' => 'normal',
        'quantity' => 100_000,
        'credentials' => [
            'ea_email' => 'fingerprint-sentinel@example.test',
            'ea_password' => 'Fingerprint Password Sentinel',
            'backup_codes' => ['84000001', '84000002', '84000003'],
        ],
    ];
    $sbc = [
        'variantId' => '01K00000000000000000000004',
        'completionCount' => 1,
        'credentials' => $coins['credentials'],
    ];

    expect(CoinsCartFingerprint::generate('user:17', $coins, 'synthetic-application-key'))
        ->not->toBe(CoinsCartFingerprint::generate(
            'user:17',
            [...$coins, 'replaceCartItemId' => '01K00000000000000000000000'],
            'synthetic-application-key',
        ))
        ->and(SbcCartFingerprint::generate('user:17', $sbc, 'synthetic-application-key'))
        ->not->toBe(SbcCartFingerprint::generate(
            'user:17',
            [...$sbc, 'replaceCartItemId' => '01K00000000000000000000000'],
            'synthetic-application-key',
        ));
});
