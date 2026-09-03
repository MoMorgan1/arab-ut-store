<?php

use App\Actions\Cart\ResolveCartOwner;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\IdempotencyKey;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ServicePriceSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array<string, mixed> */
function cartRuleConfiguration(string $group): array
{
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

    return $configuration;
}

/** @return array{product: Product, variants: array<string, ProductVariant>} */
function createCartCatalog(): array
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
        PriceRule::create([
            'name' => "Cart Coins {$group}",
            'service_type' => ServiceType::Coins,
            'configuration' => cartRuleConfiguration($group),
            'is_active' => true,
        ]);
    }

    return ['product' => $product, 'variants' => $variants];
}

/** @return array<string, mixed> */
function coinsCartPayload(array $changes = []): array
{
    $credentialsWereChanged = array_key_exists('credentials', $changes);
    $payload = [
        'platform' => 'playstation',
        'delivery' => 'normal',
        'quantity' => 100_000,
        'credentials' => [
            'ea_email' => 'cart-sentinel@example.test',
            'ea_password' => '  Opaque Cart Password Sentinel  ',
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

    if (($payload['platform'] ?? null) === 'playstation'
        && ($payload['delivery'] ?? null) === 'fast'
        && ! $credentialsWereChanged) {
        $payload['credentials']['current_balance'] = 500_000;
    }

    return $payload;
}

function addCoinsToCart(string $uri, array $payload, string $key)
{
    return test()->postJson($uri, $payload, ['Idempotency-Key' => $key]);
}

test('a guest securely adds and replays one Coins line in the same session', function () {
    createCartCatalog();
    $rawOwnerToken = str_repeat('a', 64);
    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawOwnerToken]);

    $created = addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'guest-key');
    $replayed = addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'guest-key');

    $created->assertCreated()
        ->assertJsonPath('data.cartCount', 1)
        ->assertJsonPath('data.cartUrl', '/cart');
    $replayed->assertCreated()->assertExactJson($created->json());
    $guestHmac = hash_hmac('sha256', $rawOwnerToken, (string) config('app.key'));
    $cart = Cart::sole();
    expect($created->headers->get('Cache-Control'))->toContain('no-store')
        ->and($cart->user_id)->toBeNull()
        ->and($cart->session_key)->toBe($guestHmac)
        ->and($cart->active_owner_key)->toBe("guest:{$guestHmac}")
        ->and($cart->items()->count())->toBe(1)
        ->and(CartItemSecret::count())->toBe(1)
        ->and(IdempotencyKey::count())->toBe(1)
        ->and(json_encode(DB::table('carts')->first(), JSON_THROW_ON_ERROR))->not->toContain($rawOwnerToken)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('Opaque Cart Password Sentinel')
        ->not->toContain('cart-sentinel@example.test')
        ->not->toContain('81000001')
        ->and($created->getContent())->not->toContain('Opaque Cart Password Sentinel')
        ->not->toContain('cart-sentinel@example.test')
        ->not->toContain('81000001');
});

test('guest idempotency and cart reads are isolated between sessions', function () {
    createCartCatalog();
    $firstToken = str_repeat('b', 64);
    $secondToken = str_repeat('c', 64);
    $this->withSession([ResolveCartOwner::SESSION_KEY => $firstToken]);

    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'shared-guest-key')->assertCreated();
    $this->get('/cart')->assertInertia(fn (Assert $page) => $page
        ->where('cartCount', 1)
        ->where('cart.count', 1)
        ->where('cart.items.0.configuration.coins_quantity', 100_000));
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('cartCount', 1));

    $this->flushSession();
    $this->withSession([ResolveCartOwner::SESSION_KEY => $secondToken]);
    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'shared-guest-key')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_conflict');
    addCoinsToCart(
        '/cart/items/coins',
        coinsCartPayload(['quantity' => 110_000]),
        'second-guest-key',
    )->assertCreated()->assertJsonPath('data.cartCount', 1);

    $this->get('/cart')->assertInertia(fn (Assert $page) => $page
        ->where('cartCount', 1)
        ->where('cart.count', 1)
        ->where('cart.items.0.configuration.coins_quantity', 110_000));
    expect(Cart::count())->toBe(2)
        ->and(CartItem::count())->toBe(2)
        ->and(Cart::query()->where('session_key', hash_hmac('sha256', $firstToken, (string) config('app.key')))->count())->toBe(1)
        ->and(Cart::query()->where('session_key', hash_hmac('sha256', $secondToken, (string) config('app.key')))->count())->toBe(1);
});

test('guest Coins additions still require a valid CSRF token', function () {
    createCartCatalog();
    $this->app['env'] = 'local';

    $response = addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'guest-csrf-key');

    $response->assertStatus(419);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->not->toContain('Opaque Cart Password Sentinel')
        ->and($response->getContent())->not->toContain('81000001')
        ->and(Cart::count())->toBe(0);
});

test('non JSON Coins additions are rejected without reflecting credentials', function (bool $authenticated, string $uri) {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }

    $response = $this->post($uri, coinsCartPayload(), [
        'Accept' => 'text/html',
        'Idempotency-Key' => 'non-json-key',
    ]);

    $response->assertStatus(415)->assertHeader('Content-Type', 'application/json');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->not->toContain('Opaque Cart Password Sentinel')
        ->and($response->getContent())->not->toContain('81000001')
        ->and(CartItem::count())->toBe(0);
})->with([
    'canonical guest' => [false, '/cart/items/coins'],
    'canonical authenticated user' => [true, '/cart/items/coins'],
    'localized guest' => [false, '/en/cart/items/coins'],
    'localized authenticated user' => [true, '/en/cart/items/coins'],
]);

test('supported Coins modes create one safe line per platform variant', function (array $selection, int $expectedTotal) {
    // The fast-console dataset sends the balance, which is accepted only
    // while the admin toggle is on.
    enableCoinsCurrentBalanceRequirement();
    $catalog = createCartCatalog();
    $user = User::factory()->create();
    $payload = coinsCartPayload($selection);

    if ($selection['platform'] === 'pc') {
        unset($payload['delivery']);
    }

    $this->actingAs($user);
    $first = addCoinsToCart('/cart/items/coins', $payload, 'mode-key-1');
    // One line per product variant: the same platform twice (fresh key) is a
    // 409, not a second line — quantities scale the price through tiers.
    $second = addCoinsToCart('/cart/items/coins', $payload, 'mode-key-2');

    $first->assertCreated()
        ->assertJsonPath('data.cartCount', 1)
        ->assertJsonPath('data.cartUrl', '/cart')
        ->assertJsonPath('data.quote.platform', $selection['platform'])
        ->assertJsonPath('data.quote.quantity', 100_000)
        ->assertJsonPath('data.quote.total.amountHalalah', $expectedTotal)
        ->assertJsonPath('data.quote.total.currency', 'SAR');
    $second->assertConflict()->assertJsonPath('error.code', 'already_in_cart');
    expect($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Cart::count())->toBe(1)
        ->and(CartItem::count())->toBe(1)
        ->and(CartItemSecret::count())->toBe(1);

    $cart = Cart::sole();
    $item = CartItem::oldest('id')->firstOrFail();
    $secret = $item->secret;
    expect($cart->currency)->toBe('SAR')
        ->and($cart->active_owner_key)->toBe("user:{$user->id}")
        ->and($item->product_variant_id)->toBe($catalog['variants'][$selection['platform']]->id)
        ->and($item->quantity)->toBe(1)
        ->and($item->unit_price_halalah)->toBe($expectedTotal)
        ->and($item->total_halalah)->toBe($expectedTotal)
        ->and($item->configuration)->toMatchArray([
            'service_type' => 'coins',
            'platform' => $selection['platform'],
            'market' => $selection['platform'] === 'pc' ? 'pc' : 'console',
            'delivery' => $selection['delivery'] ?? null,
            'coins_quantity' => 100_000,
            'price_version' => 11,
        ])
        ->and($secret->encrypted_payload['ea_password'])->toBe('  Opaque Cart Password Sentinel  ')
        ->and($secret->encrypted_payload['backup_codes'])->toHaveCount(3)
        ->and($secret->masked_summary)->toBe([
            'has_password' => true,
            'backup_code_count' => 3,
        ])
        ->and(json_encode($item->configuration, JSON_THROW_ON_ERROR))->not->toContain('ea_email')
        ->not->toContain('ea_password')
        ->not->toContain('backup_codes')
        ->not->toContain('cart-sentinel@example.test')
        ->not->toContain('81000001');

    $raw = DB::table('cart_item_secrets')->where('id', $secret->id)->value('encrypted_payload');
    expect($raw)->not->toContain('Opaque Cart Password Sentinel')
        ->not->toContain('81000001');
    $first->assertJsonMissing(['ea_email'])
        ->assertJsonMissing(['ea_password'])
        ->assertJsonMissing(['backup_codes']);
})->with([
    'PlayStation normal' => [['platform' => 'playstation', 'delivery' => 'normal'], 500],
    'PlayStation fast' => [['platform' => 'playstation', 'delivery' => 'fast'], 600],
    'PC' => [['platform' => 'pc', 'delivery' => null], 500],
]);

test('guest credential validation requires three distinct eight digit ASCII codes without echoing them', function (array $credentialChanges) {
    createCartCatalog();
    $payload = coinsCartPayload(['credentials' => $credentialChanges]);

    $response = addCoinsToCart('/cart/items/coins', $payload, 'validation-key');

    $response->assertUnprocessable();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->not->toContain('Opaque Cart Password Sentinel')
        ->and($response->getContent())->not->toContain('81000001')
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('Opaque Cart Password Sentinel')
        ->not->toContain('81000001')
        ->and(CartItemSecret::count())->toBe(0);
})->with([
    'only two codes' => [['backup_codes' => ['81000001', '81000002']]],
    'five codes' => [['backup_codes' => ['81000001', '81000002', '81000003', '81000004', '81000005']]],
    'duplicate codes' => [['backup_codes' => ['81000001', '81000001', '81000003']]],
    'non-ASCII digits' => [['backup_codes' => ['٨١٠٠٠٠٠١', '81000002', '81000003']]],
    'short code' => [['backup_codes' => ['8100001', '81000002', '81000003']]],
]);

test('unknown and client-authoritative fields are rejected for guests', function (array $changes) {
    createCartCatalog();

    addCoinsToCart('/cart/items/coins', coinsCartPayload($changes), 'authority-key')
        ->assertUnprocessable();
    expect(CartItem::count())->toBe(0);
})->with([
    'client price' => [['price' => 1]],
    'client product' => [['product_id' => 1]],
    'client variant' => [['variant_id' => 1]],
    'unknown credential' => [['credentials' => ['otp' => 'synthetic-otp']]],
]);

test('email and backup codes normalize without changing the opaque EA password', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    $payload = coinsCartPayload(['credentials' => [
        'ea_email' => '  CART-SENTINEL@EXAMPLE.TEST  ',
        'backup_codes' => [' 81000001 ', '81000002 ', ' 81000003'],
    ]]);

    addCoinsToCart('/cart/items/coins', $payload, 'normalization-key')->assertCreated();

    $credentials = CartItemSecret::sole()->encrypted_payload;
    expect($credentials['ea_email'])->toBe('cart-sentinel@example.test')
        ->and($credentials['ea_password'])->toBe('  Opaque Cart Password Sentinel  ')
        ->and($credentials['backup_codes'])->toBe([
            '81000001', '81000002', '81000003',
        ]);
});

test('fast console stores the complete WordPress fulfillment contract only in the encrypted owner boundary', function () {
    enableCoinsCurrentBalanceRequirement();
    createCartCatalog();
    $rawOwnerToken = str_repeat('9', 64);
    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawOwnerToken]);
    $payload = coinsCartPayload([
        'delivery' => 'fast',
        'credentials' => [
            'current_balance' => 500_000,
            'companion_market_open' => true,
            'policy_accepted' => true,
        ],
    ]);

    $created = addCoinsToCart('/cart/items/coins', $payload, 'wp-fulfillment-key');

    $created->assertCreated();
    $item = CartItem::sole();
    $secretPayload = CartItemSecret::sole()->encrypted_payload;
    expect($secretPayload)->toMatchArray([
        'ea_email' => 'cart-sentinel@example.test',
        'ea_password' => '  Opaque Cart Password Sentinel  ',
        'backup_codes' => ['81000001', '81000002', '81000003'],
        'current_balance' => 500_000,
        'companion_market_open' => true,
        'policy_version' => 'store-fulfillment-2026-08-12',
    ])->and($secretPayload['policy_accepted_at'])->toBeString();

    $read = $this->getJson("/cart/items/{$item->public_id}/credentials");
    $read->assertOk()->assertJsonPath('data.currentBalance', 500_000)
        ->assertJsonPath('data.companionMarketOpen', true)
        ->assertJsonPath('data.policyAccepted', true);

    $raw = DB::table('cart_item_secrets')->where('id', CartItemSecret::sole()->id)->value('encrypted_payload');
    expect($created->getContent())->not->toContain('500000')
        ->not->toContain('companion_market_open')
        ->and($raw)->not->toContain('500000')
        ->not->toContain('cart-sentinel@example.test');
});

test('the current balance stays off until an admin turns it on', function () {
    createCartCatalog();
    $withoutBalance = [
        'delivery' => 'fast',
        'credentials' => [
            'companion_market_open' => true,
            'policy_accepted' => true,
        ],
    ];

    // Off (the default): a fast console order needs no balance...
    addCoinsToCart('/cart/items/coins', coinsCartPayload($withoutBalance), 'balance-toggle-off')
        ->assertCreated();

    // ...and volunteering one is refused, so a stale client cannot smuggle
    // a field the store no longer collects.
    $withBalance = $withoutBalance;
    $withBalance['credentials']['current_balance'] = 500_000;

    addCoinsToCart('/cart/items/coins', coinsCartPayload($withBalance), 'balance-toggle-off-extra')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credentials.current_balance']);

    expect(CartItem::count())->toBe(1);
});

test('Coins fulfillment confirmations and conditional balance fail closed', function (array $changes) {
    // The balance rules under test only bind while the admin toggle is on.
    enableCoinsCurrentBalanceRequirement();
    createCartCatalog();
    $payload = coinsCartPayload($changes);

    addCoinsToCart('/cart/items/coins', $payload, 'wp-validation-'.md5(serialize($changes)))
        ->assertUnprocessable();

    expect(CartItem::count())->toBe(0);
})->with([
    'fast console missing balance' => [[
        'delivery' => 'fast',
        'credentials' => [
            'companion_market_open' => true,
            'policy_accepted' => true,
        ],
    ]],
    'normal console rejects balance' => [[
        'credentials' => [
            'current_balance' => 500_000,
            'companion_market_open' => true,
            'policy_accepted' => true,
        ],
    ]],
    'companion confirmation is mandatory' => [[
        'credentials' => [
            'companion_market_open' => false,
            'policy_accepted' => true,
        ],
    ]],
    'policy confirmation is mandatory' => [[
        'credentials' => [
            'companion_market_open' => true,
            'policy_accepted' => false,
        ],
    ]],
]);

test('the cart exposes encrypted EA credentials only through an owner no-store endpoint and allows owner edits', function () {
    createCartCatalog();
    $rawOwnerToken = str_repeat('f', 64);
    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawOwnerToken]);

    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'credentials-owner-key')->assertCreated();
    $item = CartItem::sole();

    $cartPage = $this->get('/cart');
    $cartPage->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.credentials.backupCodeCount', 3)
        ->where('cart.items.0.credentialsUrl', "/cart/items/{$item->public_id}/credentials"));
    expect($cartPage->getContent())
        ->not->toContain('cart-sentinel@example.test')
        ->not->toContain('Opaque Cart Password Sentinel')
        ->not->toContain('81000001');

    $read = $this->getJson("/cart/items/{$item->public_id}/credentials");
    $read->assertOk()->assertExactJson(['data' => [
        'eaEmail' => 'cart-sentinel@example.test',
        'eaPassword' => '  Opaque Cart Password Sentinel  ',
        'backupCodes' => ['81000001', '81000002', '81000003'],
        'currentBalance' => null,
        'companionMarketOpen' => true,
        'policyAccepted' => true,
    ]]);
    expect($read->headers->get('Cache-Control'))->toContain('no-store');

    $updated = $this->patchJson("/cart/items/{$item->public_id}/credentials", [
        'ea_email' => 'updated@example.test',
        'ea_password' => '  Updated opaque password  ',
        'backup_codes' => ['91000001', '91000002', '91000003'],
        'companion_market_open' => true,
        'policy_accepted' => true,
    ]);
    $updated->assertNoContent();
    expect(CartItemSecret::sole()->encrypted_payload)->toMatchArray([
        'ea_email' => 'updated@example.test',
        'ea_password' => '  Updated opaque password  ',
        'backup_codes' => ['91000001', '91000002', '91000003'],
        'companion_market_open' => true,
        'policy_version' => 'store-fulfillment-2026-08-12',
    ])->and(CartItemSecret::sole()->encrypted_payload['policy_accepted_at'])->toBeString();

    $this->flushSession();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('e', 64)]);
    $this->getJson("/cart/items/{$item->public_id}/credentials")->assertNotFound();
    $this->patchJson("/cart/items/{$item->public_id}/credentials", [
        'ea_email' => 'attacker@example.test',
        'ea_password' => 'attacker password',
        'backup_codes' => ['92000001', '92000002', '92000003'],
    ])->assertNotFound();
});

test('the credentials endpoint follows the balance toggle', function () {
    enableCoinsCurrentBalanceRequirement();
    createCartCatalog();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('d', 64)]);

    addCoinsToCart('/cart/items/coins', coinsCartPayload(['delivery' => 'fast']), 'balance-endpoint-key')
        ->assertCreated();
    $item = CartItem::sole();
    $body = [
        'ea_email' => 'toggle@example.test',
        'ea_password' => 'toggle password',
        'backup_codes' => ['93000001', '93000002', '93000003'],
        'companion_market_open' => true,
        'policy_accepted' => true,
    ];

    // On: an update without the balance is refused, one with it lands.
    $this->patchJson("/cart/items/{$item->public_id}/credentials", $body)
        ->assertUnprocessable();
    $this->patchJson("/cart/items/{$item->public_id}/credentials", [
        ...$body,
        'current_balance' => 750_000,
    ])->assertNoContent();

    // Off again: the stored balance no longer binds and volunteering one is refused.
    $schedule = ServicePriceSchedule::query()
        ->where('service_type', ServiceType::Coins)
        ->firstOrFail();
    $schedule->configuration = [
        ...(array) $schedule->configuration,
        'requiresCurrentBalance' => false,
    ];
    $schedule->save();

    $this->patchJson("/cart/items/{$item->public_id}/credentials", [
        ...$body,
        'current_balance' => 750_000,
    ])->assertUnprocessable();
    $this->patchJson("/cart/items/{$item->public_id}/credentials", $body)
        ->assertNoContent();
});

test('a cart owner can remove an item and its secret while another owner cannot', function (string $prefix) {
    createCartCatalog();
    $ownerToken = str_repeat('a', 64);
    $this->withSession([ResolveCartOwner::SESSION_KEY => $ownerToken]);

    $localeKey = $prefix === '' ? 'ar' : 'en';
    addCoinsToCart("{$prefix}/cart/items/coins", coinsCartPayload(), "remove-item-{$localeKey}")
        ->assertCreated();
    $item = CartItem::sole();
    $deleteUrl = "{$prefix}/cart/items/{$item->public_id}";

    $this->get("{$prefix}/cart")->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.deleteUrl', $deleteUrl));

    $this->flushSession();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('b', 64)]);
    $this->deleteJson($deleteUrl)->assertNotFound();
    expect(CartItem::count())->toBe(1)->and(CartItemSecret::count())->toBe(1);

    $this->flushSession();
    $this->withSession([ResolveCartOwner::SESSION_KEY => $ownerToken]);
    $response = $this->deleteJson($deleteUrl);

    // Removal is soft now: the row and its secret stay for the undo window
    // and only the scoped readers stop seeing them.
    $response->assertOk()
        ->assertJsonPath('data.cartCount', 0)
        ->assertJsonPath('data.restoreUrl', "{$prefix}/cart/items/{$item->public_id}/restore");
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(CartItem::count())->toBe(0)
        ->and(CartItem::withRemoved()->count())->toBe(1)
        ->and(CartItemSecret::count())->toBe(1);
})->with([
    'Arabic' => '',
    'English' => '/en',
]);

test('Coins cart secrets have no automatic retention deadline', function () {
    createCartCatalog();

    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'persistent-secret-key')->assertCreated();

    expect(CartItemSecret::sole()->retained_until)->toBeNull();
});

test('a whitespace-only EA password remains valid opaque input', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    $payload = coinsCartPayload(['credentials' => ['ea_password' => '   ']]);

    addCoinsToCart('/cart/items/coins', $payload, 'opaque-password-key')->assertCreated();

    expect(CartItemSecret::sole()->encrypted_payload['ea_password'])->toBe('   ');
});

test('a valid idempotency header is mandatory for guests and validation remains non-cacheable', function () {
    createCartCatalog();

    $response = $this->postJson('/cart/items/coins', coinsCartPayload());

    $response->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->not->toContain('Opaque Cart Password Sentinel')
        ->and(CartItem::count())->toBe(0);
});

test('server repricing controls the stored SAR amount', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    PriceRule::where('name', 'Cart Coins console_normal')->update([
        'configuration' => array_replace(
            cartRuleConfiguration('console_normal'),
            ['flat_rate_halalah_per_million' => 9_000],
        ),
    ]);

    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'reprice-key')
        ->assertCreated()
        ->assertJsonPath('data.quote.total.amountHalalah', 900);

    expect(CartItem::sole()->total_halalah)->toBe(900);
});

test('same idempotency key replays safely while a different payload conflicts', function () {
    createCartCatalog();
    $firstUser = User::factory()->create();
    $this->actingAs($firstUser);
    $payload = coinsCartPayload();

    $created = addCoinsToCart('/cart/items/coins', $payload, 'retry-key');
    $replayed = addCoinsToCart('/cart/items/coins', $payload, 'retry-key');
    $conflict = addCoinsToCart(
        '/cart/items/coins',
        coinsCartPayload(['quantity' => 110_000]),
        'retry-key',
    );
    $this->actingAs(User::factory()->create());
    $crossUserConflict = addCoinsToCart('/cart/items/coins', $payload, 'retry-key');

    $created->assertCreated();
    $replayed->assertCreated()->assertExactJson($created->json());
    $conflict->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
    $crossUserConflict->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
    expect(CartItem::count())->toBe(1)
        ->and(IdempotencyKey::count())->toBe(1)
        ->and(IdempotencyKey::sole()->request_hash)->toHaveLength(64)
        ->and(IdempotencyKey::sole()->response_body)->not->toContain('Opaque Cart Password Sentinel')
        ->not->toContain('cart-sentinel@example.test')
        ->not->toContain('81000001')
        ->and($conflict->headers->get('Cache-Control'))->toContain('no-store');
});

test('pricing failure closes with 503 and rolls back the whole claim', function () {
    $this->actingAs(User::factory()->create());

    $response = addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'unavailable-key');

    $response->assertStatus(503)->assertJsonPath('error.code', 'coins_pricing_unavailable');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Cart::count())->toBe(0)
        ->and(CartItem::count())->toBe(0)
        ->and(CartItemSecret::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(0);
});

test('a secret write failure rolls back cart item and idempotency state', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The deterministic write-failure trigger uses SQLite test syntax.');
    }

    createCartCatalog();
    $this->actingAs(User::factory()->create());
    DB::statement("CREATE TRIGGER fail_cart_secret BEFORE INSERT ON cart_item_secrets BEGIN SELECT RAISE(ABORT, 'synthetic secret failure'); END");
    $this->withoutExceptionHandling();

    try {
        addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'rollback-key');
    } catch (Throwable $exception) {
        expect($exception->getMessage())->not->toContain('Opaque Cart Password Sentinel')
            ->not->toContain('81000001');
    }

    expect(Cart::count())->toBe(0)
        ->and(CartItem::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(0);
});

test('unexpected debug failures return generic JSON without credential leakage', function (string $uri) {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The real-handler failure fixture uses SQLite transaction-safe triggers.');
    }

    config()->set('app.debug', true);
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    DB::statement("CREATE TRIGGER fail_sensitive_route BEFORE INSERT ON cart_item_secrets BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");

    $payload = json_encode(coinsCartPayload(), JSON_THROW_ON_ERROR);
    $response = $this->call('POST', $uri, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'text/html',
        'HTTP_IDEMPOTENCY_KEY' => 'debug-failure-key',
    ], $payload);

    $response->assertStatus(500)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('error.code', 'internal_error')
        ->assertJsonCount(2, 'error');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->not->toContain('Opaque Cart Password Sentinel')
        ->and($response->getContent())->not->toContain('81000001')
        ->and(Cart::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(0);
})->with([
    'canonical endpoint' => '/cart/items/coins',
    'localized endpoint' => '/en/cart/items/coins',
]);

test('Coins additions are throttled per cart owner', function (bool $authenticated) {
    config()->set('coins.cart.rate_limit_per_minute', 1);
    createCartCatalog();

    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    } else {
        $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('d', 64)]);
    }

    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'rate-key-1')->assertCreated();
    $limited = addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'rate-key-2');

    $limited->assertTooManyRequests();
    expect($limited->headers->get('Cache-Control'))->toContain('no-store')
        ->and(CartItem::count())->toBe(1);
})->with([
    'guest owner' => false,
    'authenticated owner' => true,
]);

test('one guest session cannot consume another guest session rate limit', function () {
    config()->set('coins.cart.rate_limit_per_minute', 1);
    createCartCatalog();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('e', 64)]);
    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'first-rate-owner')->assertCreated();

    $this->flushSession();
    $this->withSession([ResolveCartOwner::SESSION_KEY => str_repeat('f', 64)]);

    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'second-rate-owner')->assertCreated();
    expect(Cart::count())->toBe(2)
        ->and(CartItem::count())->toBe(2);
});

test('cart reads expose safe lines and credential reentry state only', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'read-key')->assertCreated();
    $cartItem = CartItem::sole();

    $response = $this->get('/cart');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('store/cart')
        ->where('cartCount', 1)
        ->where('cart.count', 1)
        ->where('cart.items.0.requiresCredentials', false)
        ->where('cart.items.0.credentials.hasPassword', true)
        ->where('cart.items.0.credentials.backupCodeCount', 3)
        ->where('cart.items.0.credentialsUrl', "/cart/items/{$cartItem->public_id}/credentials")
        ->missing('cart.items.0.credentials.maskedEmail')
        ->where('cart.items.0.configuration', [
            'service_type' => 'coins',
            'platform' => 'playstation',
            'market' => 'console',
            'delivery' => 'normal',
            'coins_quantity' => 100_000,
            'quoted_at' => $cartItem->configuration['quoted_at'],
            'price_version' => 11,
        ])
        ->missing('cart.items.0.secret'));
    expect($response->getContent())->not->toContain('Opaque Cart Password Sentinel')
        ->not->toContain('81000001')
        ->not->toContain('cart-sentinel@example.test');

    $cartItem->update(['configuration' => [
        ...$cartItem->configuration,
        'ea_password' => 'Poison Password Sentinel',
        'backup_codes' => ['85000001'],
        'supplier_debug' => ['token' => 'Poison Token Sentinel'],
    ]]);
    $poisonedRead = $this->get('/cart');
    $poisonedRead->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.configuration', [
            'service_type' => 'coins',
            'platform' => 'playstation',
            'market' => 'console',
            'delivery' => 'normal',
            'coins_quantity' => 100_000,
            'quoted_at' => $cartItem->configuration['quoted_at'],
            'price_version' => 11,
        ]));
    expect($poisonedRead->getContent())->not->toContain('Poison Password Sentinel')
        ->not->toContain('85000001')
        ->not->toContain('Poison Token Sentinel');

    CartItemSecret::sole()->update(['retained_until' => now()->subMinute()]);
    $this->get('/cart')->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.requiresCredentials', false)
        ->where('cart.items.0.credentials.backupCodeCount', 3));
});

test('cart reads ignore poisoned legacy summary emails without requiring credential reentry', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'plaintext-summary-key')->assertCreated();

    $secret = CartItemSecret::sole();
    $secret->update(['masked_summary' => [
        'email' => 'plaintext-summary-sentinel@example.test',
        'has_password' => true,
        'backup_code_count' => 3,
    ]]);

    $response = $this->get('/cart');

    $response->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.requiresCredentials', false)
        ->where('cart.items.0.credentials.hasPassword', true)
        ->where('cart.items.0.credentials.backupCodeCount', 3)
        ->missing('cart.items.0.credentials.maskedEmail'));
    expect($response->getContent())->not->toContain('plaintext-summary-sentinel@example.test');
});

test('cart reads never project accepted emails that collide with the former mask grammar', function (string $email, string $key) {
    createCartCatalog();
    $this->actingAs(User::factory()->create());

    addCoinsToCart(
        '/cart/items/coins',
        coinsCartPayload(['credentials' => ['ea_email' => $email]]),
        $key,
    )->assertCreated();

    $response = $this->get('/cart');

    $response->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.requiresCredentials', false)
        ->where('cart.items.0.credentials.hasPassword', true)
        ->where('cart.items.0.credentials.backupCodeCount', 3)
        ->missing('cart.items.0.credentials.maskedEmail'));
    expect($response->getContent())->not->toContain($email);
})->with([
    'underscore collision' => ['_***@example.test', 'underscore-collision-key'],
    'plus collision' => ['+***@example.test', 'plus-collision-key'],
    'bang collision' => ['!***@example.test', 'bang-collision-key'],
    'letter collision' => ['a***@example.test', 'letter-collision-key'],
]);

test('cart reads omit compound values nested under safe configuration keys', function (string $field, mixed $poison) {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    addCoinsToCart('/cart/items/coins', coinsCartPayload(), "compound-{$field}")->assertCreated();

    $cartItem = CartItem::sole();
    $cartItem->update(['configuration' => [
        ...$cartItem->configuration,
        $field => $poison,
    ]]);

    $response = $this->get('/cart');
    $response->assertInertia(fn (Assert $page) => $page
        ->missing("cart.items.0.configuration.{$field}"));
    expect($response->getContent())->not->toContain('Nested Configuration Poison Sentinel');
})->with([
    'service type array' => ['service_type', ['ea_password' => 'Nested Configuration Poison Sentinel']],
    'platform object' => ['platform', (object) ['secret' => 'Nested Configuration Poison Sentinel']],
    'market list' => ['market', ['Nested Configuration Poison Sentinel']],
    'delivery map' => ['delivery', ['credentials' => ['Nested Configuration Poison Sentinel']]],
    'Coins quantity object' => ['coins_quantity', (object) ['value' => 'Nested Configuration Poison Sentinel']],
    'quote timestamp list' => ['quoted_at', ['Nested Configuration Poison Sentinel']],
    'price version map' => ['price_version', ['identifier' => 'Nested Configuration Poison Sentinel']],
]);

test('cart reads omit invalid scalar values under safe configuration keys', function (string $field, mixed $invalidValue) {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    addCoinsToCart('/cart/items/coins', coinsCartPayload(), "invalid-scalar-{$field}")->assertCreated();

    $cartItem = CartItem::sole();
    $cartItem->update(['configuration' => [
        ...$cartItem->configuration,
        $field => $invalidValue,
    ]]);

    $this->get('/cart')->assertInertia(fn (Assert $page) => $page
        ->missing("cart.items.0.configuration.{$field}"));
})->with([
    'unknown service type' => ['service_type', 'unknown-service'],
    'non-string platform' => ['platform', 27],
    'unknown market' => ['market', 'mobile'],
    'unknown delivery' => ['delivery', 'instant'],
    'string Coins quantity' => ['coins_quantity', '100000'],
    'invalid quote timestamp' => ['quoted_at', 'tomorrow'],
    'non-positive price version' => ['price_version', 0],
]);

test('cart reads preserve valid nullable summaries on both storefront locales', function (string $uri) {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    $payload = coinsCartPayload(['platform' => 'pc', 'quantity' => 100_000]);
    unset($payload['delivery']);
    addCoinsToCart('/cart/items/coins', $payload, 'valid-read-'.($uri === '/cart' ? 'ar' : 'en'))->assertCreated();

    $cartItem = CartItem::sole();
    $this->get($uri)->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.configuration', [
            'service_type' => 'coins',
            'platform' => 'pc',
            'market' => 'pc',
            'delivery' => null,
            'coins_quantity' => 100_000,
            'quoted_at' => $cartItem->configuration['quoted_at'],
            'price_version' => 11,
        ]));
})->with([
    'Arabic cart' => '/cart',
    'English cart' => '/en/cart',
]);

test('the obsolete authenticated resume boundary is not routable', function (string $uri) {
    $this->get($uri)->assertNotFound();
})->with([
    'Arabic' => '/cart/items/coins/resume?platform=pc&quantity=50000',
    'English' => '/en/cart/items/coins/resume?platform=pc&quantity=50000',
]);

test('localized guest writes preserve the English storefront', function () {
    createCartCatalog();

    addCoinsToCart('/en/cart/items/coins', coinsCartPayload(), 'localized-key')
        ->assertCreated()
        ->assertJsonPath('data.cartUrl', '/en/cart');
    $this->get('/en/cart')->assertInertia(fn (Assert $page) => $page
        ->where('cartCount', 1)
        ->where('cart.count', 1));
});

test('adding the same Coins variant twice returns 409 already_in_cart', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());

    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'coins-duplicate-1')->assertCreated();

    $second = addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'coins-duplicate-2');

    $second->assertConflict()
        ->assertJsonPath('error.code', 'already_in_cart')
        ->assertJsonPath('error.message', trans('store.cart.already_in_cart'))
        ->assertJsonPath('error.cartUrl', '/cart');
    expect($second->headers->get('Cache-Control'))->toContain('no-store')
        ->and(CartItem::count())->toBe(1);
});

test('adding a different Coins platform variant creates a second line', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());

    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'coins-platform-1')->assertCreated();

    $pcPayload = coinsCartPayload(['platform' => 'pc']);
    unset($pcPayload['delivery']);

    addCoinsToCart('/cart/items/coins', $pcPayload, 'coins-platform-2')
        ->assertCreated()
        ->assertJsonPath('data.cartCount', 2);

    expect(CartItem::count())->toBe(2);
});

test('the storefront shares cartVariantIds alongside cartCount', function () {
    $catalog = createCartCatalog();
    $this->actingAs(User::factory()->create());

    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'coins-shared-variant')->assertCreated();

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('cartCount', 1)
        ->where('cartVariantIds', [$catalog['variants']['playstation']->public_id]));
});
