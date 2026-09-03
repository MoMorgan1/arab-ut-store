<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\IdempotencyKey;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/** @return array{product: Product, variant: ProductVariant} */
function createSbcCartProduct(array $productChanges = [], array $variantChanges = []): array
{
    $product = Product::factory()->create(array_replace([
        'service_type' => ServiceType::Sbc,
        'name_ar' => 'تحدي بناء تشكيلة',
        'name_en' => 'Squad Building Challenge',
        'is_visible' => true,
        'archived_at' => null,
    ], $productChanges));
    $variant = ProductVariant::factory()->for($product)->create(array_replace([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => 12_500,
        'sale_price_halalah' => null,
        'price_version' => 4,
        'is_active' => true,
    ], $variantChanges));

    return compact('product', 'variant');
}

/** @return array<string, mixed> */
function sbcCartPayload(ProductVariant $variant, array $changes = []): array
{
    $payload = [
        'variantId' => $variant->public_id,
        'completionCount' => 1,
        'credentials' => [
            'ea_email' => 'sbc-owner@example.test',
            'ea_password' => '  Opaque SBC Password  ',
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

test('a guest adds an SBC with encrypted persistent credentials and a secret free response', function () {
    ['variant' => $variant] = createSbcCartProduct();
    $payload = sbcCartPayload($variant);

    $response = $this->postJson('/cart/items/sbc', $payload, ['Idempotency-Key' => 'sbc-first-add']);

    $response->assertCreated()
        ->assertJsonPath('data.cartCount', 1)
        ->assertJsonPath('data.cartUrl', '/cart');
    $item = CartItem::sole();
    $secret = CartItemSecret::sole();
    expect($item->configuration)->toMatchArray([
        'service_type' => 'sbc',
        'platform' => 'playstation',
        'market' => 'console',
        'completion_count' => 1,
        'price_version' => 4,
    ])->not->toHaveKey('credentials')
        ->and($item->unit_price_halalah)->toBe(12_500)
        ->and($item->total_halalah)->toBe(12_500)
        ->and($secret->retained_until)->toBeNull()
        ->and($secret->masked_summary)->toBe([
            'has_password' => true,
            'backup_code_count' => 3,
        ])
        ->and($secret->encrypted_payload)->toBe($payload['credentials'])
        ->and(DB::table('cart_item_secrets')->value('encrypted_payload'))->not->toContain('Opaque SBC Password')
        ->and($response->getContent())->not->toContain('sbc-owner@example.test')
        ->not->toContain('93000001')
        ->not->toContain('Opaque SBC Password');
});

test('an SBC bundle uses the exact server tier while cart and Paylink quantity remain one', function () {
    ['variant' => $variant] = createSbcCartProduct(variantChanges: [
        'price_halalah' => 57_000,
        'configuration' => [
            'completionPricing' => [
                'version' => 1,
                'repeatable' => true,
                'maximum' => 10,
                'tiers' => [
                    ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 57_000],
                    ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 107_900],
                ],
            ],
        ],
    ]);

    $response = $this->postJson('/cart/items/sbc', sbcCartPayload($variant, [
        'completionCount' => 10,
    ]), ['Idempotency-Key' => 'sbc-ten-completions']);

    $response->assertCreated();
    $item = CartItem::sole();
    expect($item->quantity)->toBe(1)
        ->and($item->unit_price_halalah)->toBe(107_900)
        ->and($item->total_halalah)->toBe(107_900)
        ->and($item->configuration)->toMatchArray(['completion_count' => 10])
        ->and($response->getContent())->not->toContain('completionPricing')
        ->not->toContain('multiplierBps');
    // The cart total rides along for the added-to-cart sheet; the pricing
    // schedule internals above stay out of the response.
    $response->assertJsonPath('data.cartTotalHalalah', 107_900);
});

test('SBC completion counts must be required positive integers', function (array $changes) {
    ['variant' => $variant] = createSbcCartProduct(variantChanges: [
        'price_halalah' => 57_000,
        'configuration' => [
            'completionPricing' => [
                'version' => 1,
                'repeatable' => true,
                'maximum' => 10,
                'tiers' => [
                    ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 57_000],
                    ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 107_900],
                ],
            ],
        ],
    ]);
    $payload = sbcCartPayload($variant, $changes);

    if (array_key_exists('completionCount', $changes) && $changes['completionCount'] === null) {
        unset($payload['completionCount']);
    }

    $this->postJson('/cart/items/sbc', $payload, [
        'Idempotency-Key' => 'sbc-invalid-count-'.fake()->unique()->numerify('####'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['completionCount']);

    expect(CartItem::count())->toBe(0);
})->with([
    'missing' => [['completionCount' => null]],
    'string' => [['completionCount' => '10']],
    'zero' => [['completionCount' => 0]],
]);

test('an unavailable SBC completion count fails closed', function () {
    ['variant' => $variant] = createSbcCartProduct(variantChanges: [
        'price_halalah' => 57_000,
        'configuration' => [
            'completionPricing' => [
                'version' => 1,
                'repeatable' => true,
                'maximum' => 10,
                'tiers' => [
                    ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 57_000],
                    ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 107_900],
                ],
            ],
        ],
    ]);

    $response = $this->postJson('/cart/items/sbc', sbcCartPayload($variant, [
        'completionCount' => 7,
    ]), ['Idempotency-Key' => 'sbc-undeclared-count']);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'catalog_item_unavailable');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(CartItem::count())->toBe(0);
});

test('an idempotency key conflicts when the selected completion count changes', function () {
    ['variant' => $variant] = createSbcCartProduct(variantChanges: [
        'price_halalah' => 57_000,
        'configuration' => [
            'completionPricing' => [
                'version' => 1,
                'repeatable' => true,
                'maximum' => 10,
                'tiers' => [
                    ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 57_000],
                    ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 107_900],
                ],
            ],
        ],
    ]);

    $this->postJson('/cart/items/sbc', sbcCartPayload($variant, ['completionCount' => 5]), [
        'Idempotency-Key' => 'sbc-count-conflict',
    ])->assertCreated();
    $this->postJson('/cart/items/sbc', sbcCartPayload($variant, ['completionCount' => 10]), [
        'Idempotency-Key' => 'sbc-count-conflict',
    ])->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');

    expect(CartItem::count())->toBe(1);
});

test('the generic catalog endpoint cannot create an SBC line without credentials', function () {
    ['variant' => $variant] = createSbcCartProduct();

    $this->postJson('/cart/items/catalog', ['variantId' => $variant->public_id], [
        'Idempotency-Key' => 'generic-sbc-denied',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'catalog_item_unavailable');

    expect(CartItem::count())->toBe(0)
        ->and(CartItemSecret::count())->toBe(0);
});

test('an exact SBC retry replays one safe line while changed credentials conflict', function () {
    ['variant' => $variant] = createSbcCartProduct();
    $payload = sbcCartPayload($variant);

    $created = $this->postJson('/cart/items/sbc', $payload, ['Idempotency-Key' => 'sbc-replay']);
    $replayed = $this->postJson('/cart/items/sbc', $payload, ['Idempotency-Key' => 'sbc-replay']);
    $conflict = $this->postJson('/cart/items/sbc', sbcCartPayload($variant, [
        'credentials' => ['ea_email' => 'changed@example.test'],
    ]), ['Idempotency-Key' => 'sbc-replay']);

    $created->assertCreated();
    $replayed->assertCreated()->assertExactJson($created->json());
    $conflict->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');
    expect(CartItem::count())->toBe(1)
        ->and(CartItemSecret::count())->toBe(1)
        ->and(IdempotencyKey::count())->toBe(1)
        ->and(IdempotencyKey::sole()->response_body)->not->toContain('sbc-owner@example.test')
        ->not->toContain('93000001')
        ->not->toContain('Opaque SBC Password');
});

test('SBC credentials normalize email and codes but preserve an opaque password', function () {
    ['variant' => $variant] = createSbcCartProduct();

    $this->postJson('/en/cart/items/sbc', sbcCartPayload($variant, [
        'credentials' => [
            'ea_email' => '  SBC-OWNER@EXAMPLE.TEST ',
            'backup_codes' => [' 93000001 ', '93000002 ', ' 93000003'],
        ],
    ]), ['Idempotency-Key' => 'sbc-normalized'])
        ->assertCreated()
        ->assertJsonPath('data.cartUrl', '/en/cart');

    expect(CartItemSecret::sole()->encrypted_payload)->toBe([
        'ea_email' => 'sbc-owner@example.test',
        'ea_password' => '  Opaque SBC Password  ',
        'backup_codes' => ['93000001', '93000002', '93000003'],
    ]);
});

test('SBC additions require exactly three distinct ASCII eight digit backup codes', function (array $credentials) {
    ['variant' => $variant] = createSbcCartProduct();

    $this->postJson('/cart/items/sbc', sbcCartPayload($variant, [
        'credentials' => $credentials,
    ]), ['Idempotency-Key' => 'sbc-invalid-'.fake()->unique()->numerify('####')])
        ->assertUnprocessable();

    expect(CartItem::count())->toBe(0)
        ->and(CartItemSecret::count())->toBe(0);
})->with([
    'two codes' => [['backup_codes' => ['93000001', '93000002']]],
    'four codes' => [['backup_codes' => ['93000001', '93000002', '93000003', '93000004']]],
    'duplicate codes' => [['backup_codes' => ['93000001', '93000001', '93000003']]],
    'Arabic digits' => [['backup_codes' => ['٩٣٠٠٠٠٠١', '93000002', '93000003']]],
    'short code' => [['backup_codes' => ['9300001', '93000002', '93000003']]],
    'unknown credential' => [['otp' => 'must-not-be-accepted']],
]);

test('only a public active priced SBC variant can use the sensitive add boundary', function (
    array $productChanges,
    array $variantChanges,
) {
    ['variant' => $variant] = createSbcCartProduct($productChanges, $variantChanges);

    $this->postJson('/cart/items/sbc', sbcCartPayload($variant), [
        'Idempotency-Key' => 'sbc-unavailable-'.fake()->unique()->numerify('####'),
    ])->assertUnprocessable()->assertJsonPath('error.code', 'catalog_item_unavailable');

    expect(CartItem::count())->toBe(0)
        ->and(CartItemSecret::count())->toBe(0);
})->with([
    'hidden product' => [['is_visible' => false], []],
    'archived product' => [['archived_at' => '2026-08-12 00:00:00'], []],
    'inactive variant' => [[], ['is_active' => false]],
    'zero price' => [[], ['price_halalah' => 0]],
    'nonpositive price version' => [[], ['price_version' => 0]],
    'non SBC product and variant' => [
        ['service_type' => ServiceType::Objectives],
        ['service_type' => ServiceType::Objectives],
    ],
]);

test('the SBC cart exposes credentials only through the owner no store endpoint and keeps edits indefinitely', function () {
    ['variant' => $variant] = createSbcCartProduct();
    $this->postJson('/cart/items/sbc', sbcCartPayload($variant), [
        'Idempotency-Key' => 'sbc-owner-edit',
    ])->assertCreated();
    $item = CartItem::sole();

    $this->get('/cart')->assertInertia(fn ($page) => $page
        ->where('cart.items.0.credentials.hasPassword', true)
        ->where('cart.items.0.credentials.backupCodeCount', 3)
        ->where('cart.items.0.configuration.completion_count', 1)
        ->where('cart.items.0.credentialsUrl', "/cart/items/{$item->public_id}/credentials")
        ->where('cart.items.0.requiresCredentials', false)
        ->missing('cart.items.0.credentials.eaPassword'));

    $read = $this->getJson("/cart/items/{$item->public_id}/credentials")
        ->assertOk()
        ->assertJsonPath('data.eaEmail', 'sbc-owner@example.test')
        ->assertJsonPath('data.eaPassword', '  Opaque SBC Password  ')
        ->assertJsonCount(3, 'data.backupCodes');
    expect($read->headers->get('Cache-Control'))->toContain('no-store');

    $this->patchJson("/cart/items/{$item->public_id}/credentials", [
        'ea_email' => 'updated-sbc@example.test',
        'ea_password' => 'Updated SBC password',
        'backup_codes' => ['94000001', '94000002', '94000003'],
    ])->assertNoContent();

    expect(CartItemSecret::sole()->retained_until)->toBeNull()
        ->and(CartItemSecret::sole()->encrypted_payload['ea_email'])->toBe('updated-sbc@example.test');

    $this->flushSession();
    $this->getJson("/cart/items/{$item->public_id}/credentials")->assertNotFound();
});

test('unexpected SBC debug failures stay generic JSON and never render submitted credentials', function (string $uri) {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The real-handler failure fixture uses a SQLite trigger.');
    }

    config()->set('app.debug', true);
    ['variant' => $variant] = createSbcCartProduct();
    DB::statement("CREATE TRIGGER fail_sbc_secret BEFORE INSERT ON cart_item_secrets BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
    $payload = json_encode(sbcCartPayload($variant), JSON_THROW_ON_ERROR);

    $response = $this->call('POST', $uri, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'text/html',
        'HTTP_IDEMPOTENCY_KEY' => 'sbc-debug-failure',
    ], $payload);

    $response->assertStatus(500)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('error.code', 'internal_error');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->not->toContain('sbc-owner@example.test')
        ->not->toContain('Opaque SBC Password')
        ->not->toContain('93000001')
        ->and(CartItem::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(0);
})->with([
    'canonical endpoint' => '/cart/items/sbc',
    'localized endpoint' => '/en/cart/items/sbc',
]);

test('adding the same SBC variant twice returns 409 already_in_cart', function () {
    ['variant' => $variant] = createSbcCartProduct();

    $this->postJson('/cart/items/sbc', sbcCartPayload($variant), ['Idempotency-Key' => 'sbc-duplicate-1'])
        ->assertCreated();

    $second = $this->postJson('/cart/items/sbc', sbcCartPayload($variant), ['Idempotency-Key' => 'sbc-duplicate-2']);

    $second->assertConflict()
        ->assertJsonPath('error.code', 'already_in_cart')
        ->assertJsonPath('error.message', trans('store.cart.already_in_cart'))
        ->assertJsonPath('error.cartUrl', '/cart');
    expect($second->headers->get('Cache-Control'))->toContain('no-store')
        ->and(CartItem::count())->toBe(1);
});

test('adding another SBC platform variant creates a second line', function () {
    ['variant' => $first] = createSbcCartProduct();
    ['variant' => $second] = createSbcCartProduct(variantChanges: ['platform' => Platform::Pc]);

    $this->postJson('/cart/items/sbc', sbcCartPayload($first), ['Idempotency-Key' => 'sbc-platform-1'])
        ->assertCreated();
    $this->postJson('/cart/items/sbc', sbcCartPayload($second), ['Idempotency-Key' => 'sbc-platform-2'])
        ->assertCreated()
        ->assertJsonPath('data.cartCount', 2);

    expect(CartItem::count())->toBe(2);
});
