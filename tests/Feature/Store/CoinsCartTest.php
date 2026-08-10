<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\IdempotencyKey;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
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
    $payload = [
        'platform' => 'playstation',
        'delivery' => 'normal',
        'quantity' => 100_000,
        'credentials' => [
            'ea_email' => 'cart-sentinel@example.test',
            'ea_password' => '  Opaque Cart Password Sentinel  ',
            'backup_codes' => ['81000001', '81000002', '81000003', '81000004', '81000005'],
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

function addCoinsToCart(string $uri, array $payload, string $key)
{
    return test()->postJson($uri, $payload, ['Idempotency-Key' => $key]);
}

test('guest Coins additions are unauthorized and never cached', function () {
    $response = addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'guest-key');

    $response->assertUnauthorized();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->not->toContain('Opaque Cart Password Sentinel')
        ->and($response->getContent())->not->toContain('81000001')
        ->and(Cart::count())->toBe(0);
});

test('supported Coins modes create distinct safe lines with encrypted credentials', function (array $selection, int $expectedTotal) {
    $catalog = createCartCatalog();
    $user = User::factory()->create();
    $payload = coinsCartPayload($selection);

    if ($selection['platform'] === 'pc') {
        unset($payload['delivery']);
    }

    $this->actingAs($user);
    $first = addCoinsToCart('/cart/items/coins', $payload, 'mode-key-1');
    $second = addCoinsToCart('/cart/items/coins', $payload, 'mode-key-2');

    $first->assertCreated()
        ->assertJsonPath('data.cartCount', 1)
        ->assertJsonPath('data.cartUrl', '/cart')
        ->assertJsonPath('data.quote.platform', $selection['platform'])
        ->assertJsonPath('data.quote.quantity', 100_000)
        ->assertJsonPath('data.quote.total.amountHalalah', $expectedTotal)
        ->assertJsonPath('data.quote.total.currency', 'SAR');
    $second->assertCreated()->assertJsonPath('data.cartCount', 2);
    expect($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Cart::count())->toBe(1)
        ->and(CartItem::count())->toBe(2)
        ->and(CartItemSecret::count())->toBe(2);

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
        ->and($secret->encrypted_payload['backup_codes'])->toHaveCount(5)
        ->and($secret->masked_summary)->toBe([
            'email' => 'c***@example.test',
            'has_password' => true,
            'backup_code_count' => 5,
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

test('credential validation requires five distinct eight digit ASCII codes without echoing them', function (array $credentialChanges) {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
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
    'only four codes' => [['backup_codes' => ['81000001', '81000002', '81000003', '81000004']]],
    'duplicate codes' => [['backup_codes' => ['81000001', '81000001', '81000003', '81000004', '81000005']]],
    'non-ASCII digits' => [['backup_codes' => ['٨١٠٠٠٠٠١', '81000002', '81000003', '81000004', '81000005']]],
    'short code' => [['backup_codes' => ['8100001', '81000002', '81000003', '81000004', '81000005']]],
]);

test('unknown and client-authoritative fields are rejected', function (array $changes) {
    createCartCatalog();
    $this->actingAs(User::factory()->create());

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
        'backup_codes' => [' 81000001 ', '81000002 ', ' 81000003', '81000004', '81000005'],
    ]]);

    addCoinsToCart('/cart/items/coins', $payload, 'normalization-key')->assertCreated();

    $credentials = CartItemSecret::sole()->encrypted_payload;
    expect($credentials['ea_email'])->toBe('cart-sentinel@example.test')
        ->and($credentials['ea_password'])->toBe('  Opaque Cart Password Sentinel  ')
        ->and($credentials['backup_codes'])->toBe([
            '81000001', '81000002', '81000003', '81000004', '81000005',
        ]);
});

test('a whitespace-only EA password remains valid opaque input', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    $payload = coinsCartPayload(['credentials' => ['ea_password' => '   ']]);

    addCoinsToCart('/cart/items/coins', $payload, 'opaque-password-key')->assertCreated();

    expect(CartItemSecret::sole()->encrypted_payload['ea_password'])->toBe('   ');
});

test('a valid idempotency header is mandatory and validation remains non-cacheable', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());

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

test('Coins additions are throttled per authenticated user', function () {
    config()->set('coins.cart.rate_limit_per_minute', 1);
    createCartCatalog();
    $this->actingAs(User::factory()->create());

    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'rate-key-1')->assertCreated();
    $limited = addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'rate-key-2');

    $limited->assertTooManyRequests();
    expect($limited->headers->get('Cache-Control'))->toContain('no-store')
        ->and(CartItem::count())->toBe(1);
});

test('cart reads expose safe lines and credential reentry state only', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());
    addCoinsToCart('/cart/items/coins', coinsCartPayload(), 'read-key')->assertCreated();

    $response = $this->get('/cart');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('store/simple-page')
        ->where('cart.count', 1)
        ->where('cart.items.0.requiresCredentials', false)
        ->missing('cart.items.0.secret'));
    expect($response->getContent())->not->toContain('Opaque Cart Password Sentinel')
        ->not->toContain('81000001');

    CartItemSecret::sole()->update(['retained_until' => now()->subMinute()]);
    $this->artisan('cart-secrets:purge')->assertSuccessful();
    $this->get('/cart')->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.requiresCredentials', true));
});

test('resume stores only validated safe selection as the intended login destination', function () {
    $safeUrl = '/cart/items/coins/resume?platform=playstation&delivery=fast&quantity=100000';

    $this->get($safeUrl)
        ->assertRedirect('/login');
    $intended = (string) session('url.intended');
    parse_str((string) parse_url($intended, PHP_URL_QUERY), $intendedQuery);
    expect(parse_url($intended, PHP_URL_PATH))->toBe('/cart/items/coins/resume')
        ->and($intendedQuery)->toBe([
            'delivery' => 'fast',
            'platform' => 'playstation',
            'quantity' => '100000',
        ])
        ->and(session()->all())->not->toContain('credentials');

    $this->flushSession();
    $this->get('/cart/items/coins/resume?platform=pc&quantity=50000&ea_password=unsafe-sentinel')
        ->assertUnprocessable();
    expect(session()->all())->not->toContain('unsafe-sentinel');
});

test('localized writes and resume returns preserve the English storefront', function () {
    createCartCatalog();
    $this->actingAs(User::factory()->create());

    addCoinsToCart('/en/cart/items/coins', coinsCartPayload(), 'localized-key')
        ->assertCreated()
        ->assertJsonPath('data.cartUrl', '/en/cart');
    $this->get('/en/cart/items/coins/resume?platform=pc&quantity=50000')
        ->assertRedirect('/en/?platform=pc&quantity=50000&step=credentials');
});
