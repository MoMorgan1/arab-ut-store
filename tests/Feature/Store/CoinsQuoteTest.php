<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\ExchangeRate;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function quoteRuleConfiguration(string $group, array $changes = []): array
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

    return array_replace($configuration, $changes);
}

/** @return array{product: Product, variants: array<string, ProductVariant>} */
function createQuoteCatalog(bool $withRules = true): array
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'name_ar' => 'كوينز ألتيميت تيم',
        'name_en' => 'Ultimate Team Coins',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variants = [];

    foreach (Platform::cases() as $platform) {
        $variants[$platform->value] = ProductVariant::factory()->for($product)->create([
            'service_type' => ServiceType::Coins,
            'platform' => $platform,
            'is_active' => true,
        ]);
    }

    if ($withRules) {
        foreach (['console_normal', 'console_fast', 'pc'] as $group) {
            PriceRule::create([
                'product_variant_id' => null,
                'name' => "Coins {$group}",
                'service_type' => ServiceType::Coins,
                'platform' => null,
                'configuration' => quoteRuleConfiguration($group),
                'is_active' => true,
            ]);
        }
    }

    return ['product' => $product, 'variants' => $variants];
}

function assertQuoteUnavailable(TestResponse $response): void
{
    assertQuoteIsNotStored($response);
    $response->assertStatus(503)
        ->assertJsonPath('error.code', 'coins_pricing_unavailable');
}

function assertQuoteIsNotStored(TestResponse $response): void
{
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
}

test('PlayStation is the canonical combined PS and Xbox Coins option', function () {
    $catalog = createQuoteCatalog();

    $playStation = $this->getJson('/coins/quote?platform=playstation&delivery=normal&quantity=100000');

    assertQuoteIsNotStored($playStation);
    $playStation->assertOk()
        ->assertJsonPath('data.productId', $catalog['product']->public_id)
        ->assertJsonPath('data.variantId', $catalog['variants']['playstation']->public_id)
        ->assertJsonPath('data.priceVersion', 1)
        ->assertJsonPath('data.platform', 'playstation')
        ->assertJsonPath('data.market', 'console')
        ->assertJsonPath('data.delivery', 'normal')
        ->assertJsonPath('data.quantity', 100_000)
        ->assertJsonPath('data.total.amountHalalah', 500)
        ->assertJsonPath('data.total.currency', 'SAR')
        ->assertJsonPath('data.displayTotal.amountMinor', 500)
        ->assertJsonPath('data.displayTotal.currency', 'SAR');

    expect($playStation->json('data.pricedAt'))->toBeString()
        ->and(now()->parse($playStation->json('data.pricedAt'))->isUtc())->toBeTrue();
});

test('a foreign display quote keeps SAR authority and uses only the session-selected rate', function () {
    createQuoteCatalog();
    ExchangeRate::create([
        'base_currency' => 'SAR',
        'quote_currency' => 'USD',
        'rate' => '0.26666667',
        'source' => 'exchange-rate-api-open-access',
        'fetched_at' => now(),
    ]);
    Http::preventStrayRequests();

    $response = $this->withSession(['display_currency' => 'USD'])
        ->getJson('/en/coins/quote?platform=pc&quantity=50000');

    assertQuoteIsNotStored($response);
    $response->assertOk()
        ->assertJsonPath('data.total.amountHalalah', 250)
        ->assertJsonPath('data.total.currency', 'SAR')
        ->assertJsonPath('data.displayTotal.amountMinor', 67)
        ->assertJsonPath('data.displayTotal.currency', 'USD')
        ->assertJsonMissingPath('data.checkoutCurrency');
});

test('missing or exactly 30 hour old display rates fail a foreign quote closed', function (string $failure) {
    createQuoteCatalog();

    if ($failure === 'stale') {
        ExchangeRate::create([
            'base_currency' => 'SAR',
            'quote_currency' => 'EUR',
            'rate' => '0.25000000',
            'source' => 'exchange-rate-api-open-access',
            'fetched_at' => now()->subHours(30),
        ]);
    }

    assertQuoteUnavailable(
        $this->withSession(['display_currency' => 'EUR'])
            ->getJson('/coins/quote?platform=pc&quantity=50000'),
    );
})->with(['missing', 'stale']);

test('Xbox is rejected because it is not a separate public Coins option', function () {
    createQuoteCatalog();

    $response = $this->getJson('/coins/quote?platform=xbox&delivery=normal&quantity=50000');

    assertQuoteIsNotStored($response);
    $response->assertUnprocessable();
});

test('PC derives its market and omits delivery', function () {
    $catalog = createQuoteCatalog();

    $response = $this->getJson('/en/coins/quote?platform=pc&quantity=50000');
    assertQuoteIsNotStored($response);
    $response
        ->assertOk()
        ->assertJsonPath('data.variantId', $catalog['variants']['pc']->public_id)
        ->assertJsonPath('data.platform', 'pc')
        ->assertJsonPath('data.market', 'pc')
        ->assertJsonPath('data.delivery', null);
});

test('a quote requires only the active rules used by its selected mode', function (string $query, string $group) {
    createQuoteCatalog(withRules: false);
    PriceRule::create([
        'name' => "Only {$group} pricing",
        'service_type' => ServiceType::Coins,
        'configuration' => quoteRuleConfiguration($group),
        'is_active' => true,
    ]);

    $this->getJson('/coins/quote?'.$query)->assertOk();
})->with([
    'normal console' => ['platform=playstation&delivery=normal&quantity=50000', 'console_normal'],
    'PC' => ['platform=pc&quantity=50000', 'pc'],
]);

test('a selected mode ignores a malformed active rule from an unrelated pricing group', function (
    string $query,
    string $selectedGroup,
    string $unrelatedGroup,
) {
    createQuoteCatalog(withRules: false);

    PriceRule::create([
        'name' => "Valid {$selectedGroup} pricing",
        'service_type' => ServiceType::Coins,
        'configuration' => quoteRuleConfiguration($selectedGroup),
        'is_active' => true,
    ]);
    PriceRule::create([
        'name' => "Malformed {$unrelatedGroup} pricing",
        'service_type' => ServiceType::Coins,
        'configuration' => quoteRuleConfiguration($unrelatedGroup, [
            'multipliers_basis_points' => [],
        ]),
        'is_active' => true,
    ]);

    $this->getJson('/coins/quote?'.$query)->assertOk();
})->with([
    'normal console ignores malformed PC' => [
        'platform=playstation&delivery=normal&quantity=50000',
        'console_normal',
        'pc',
    ],
    'PC ignores malformed normal console' => [
        'platform=pc&quantity=50000',
        'pc',
        'console_normal',
    ],
]);

test('a selected mode ignores duplicate active rules from an unrelated pricing group', function () {
    createQuoteCatalog(withRules: false);

    PriceRule::create([
        'name' => 'Valid normal console pricing',
        'service_type' => ServiceType::Coins,
        'configuration' => quoteRuleConfiguration('console_normal'),
        'is_active' => true,
    ]);

    foreach (['PC pricing one', 'PC pricing two'] as $name) {
        PriceRule::create([
            'name' => $name,
            'service_type' => ServiceType::Coins,
            'configuration' => quoteRuleConfiguration('pc'),
            'is_active' => true,
        ]);
    }

    $this->getJson('/coins/quote?platform=playstation&delivery=normal&quantity=50000')
        ->assertOk();
});

test('an unclassifiable active Coins pricing rule fails the selected quote closed', function (mixed $configuration) {
    createQuoteCatalog();
    PriceRule::create([
        'name' => 'Unclassifiable Coins pricing',
        'service_type' => ServiceType::Coins,
        'configuration' => $configuration,
        'is_active' => true,
    ]);

    assertQuoteUnavailable($this->getJson('/coins/quote?platform=pc&quantity=50000'));
})->with([
    'non-array configuration' => ['legacy-rule'],
    'missing group' => [['version' => 1]],
    'non-string group' => [['group' => 42]],
    'unknown group' => [['group' => 'legacy_console']],
]);

test('a fast exact override wins without a usable normal pricing rule', function (bool $includeMalformedNormal) {
    createQuoteCatalog(withRules: false);

    PriceRule::create([
        'name' => 'Pinned fast pricing',
        'service_type' => ServiceType::Coins,
        'configuration' => quoteRuleConfiguration('console_fast', [
            'exact_overrides_halalah' => ['50000' => 1_200],
        ]),
        'is_active' => true,
    ]);

    if ($includeMalformedNormal) {
        PriceRule::create([
            'name' => 'Malformed normal pricing',
            'service_type' => ServiceType::Coins,
            'configuration' => quoteRuleConfiguration('console_normal', [
                'multipliers_basis_points' => [],
            ]),
            'is_active' => true,
        ]);
    }

    $response = $this->getJson('/coins/quote?platform=playstation&delivery=fast&quantity=50000');

    assertQuoteIsNotStored($response);
    $response->assertOk()
        ->assertJsonPath('data.total.amountHalalah', 1_200);
})->with([
    'normal pricing is missing' => false,
    'normal pricing is malformed' => true,
]);

test('fast formula pricing still requires a usable normal pricing rule', function () {
    createQuoteCatalog(withRules: false);

    PriceRule::create([
        'name' => 'Formula fast pricing',
        'service_type' => ServiceType::Coins,
        'configuration' => quoteRuleConfiguration('console_fast'),
        'is_active' => true,
    ]);

    assertQuoteUnavailable(
        $this->getJson('/coins/quote?platform=playstation&delivery=fast&quantity=50000'),
    );
});

test('delivery combinations are validated at the request boundary', function (string $query) {
    createQuoteCatalog();

    $response = $this->getJson('/coins/quote?'.$query);
    assertQuoteIsNotStored($response);
    $response->assertUnprocessable();
})->with([
    'console requires delivery' => 'platform=playstation&quantity=50000',
    'PC rejects delivery' => 'platform=pc&delivery=normal&quantity=50000',
    'PC rejects an explicitly empty delivery' => 'platform=pc&delivery=&quantity=50000',
]);

test('PC requires the delivery key to be absent from JSON input', function () {
    createQuoteCatalog();

    $response = $this->json('GET', '/coins/quote', [
        'platform' => 'pc',
        'delivery' => null,
        'quantity' => 50_000,
    ]);

    assertQuoteIsNotStored($response);
    $response->assertUnprocessable();
});

test('quantity limits and increments are enforced for each mode', function (string $query, int $status) {
    createQuoteCatalog();

    $response = $this->getJson('/coins/quote?'.$query);
    assertQuoteIsNotStored($response);

    $status === 200 ? $response->assertOk() : $response->assertUnprocessable();
})->with([
    'minimum' => ['platform=playstation&delivery=normal&quantity=50000', 200],
    'below minimum' => ['platform=playstation&delivery=normal&quantity=40000', 422],
    'wrong increment' => ['platform=playstation&delivery=normal&quantity=55000', 422],
    'normal maximum' => ['platform=playstation&delivery=normal&quantity=2000000', 200],
    'normal over maximum' => ['platform=playstation&delivery=normal&quantity=2010000', 422],
    'fast maximum' => ['platform=playstation&delivery=fast&quantity=20000000', 200],
    'fast over maximum' => ['platform=playstation&delivery=fast&quantity=20010000', 422],
    'PC maximum' => ['platform=pc&quantity=20000000', 200],
    'PC over maximum' => ['platform=pc&quantity=20010000', 422],
]);

test('the quote request rejects every top-level field outside its exact public contract', function (string $field, mixed $value) {
    createQuoteCatalog();

    $response = $this->getJson('/coins/quote?'.http_build_query([
        'platform' => 'pc',
        'quantity' => 50_000,
        $field => $value,
    ]));

    assertQuoteIsNotStored($response);
    $response->assertUnprocessable();
})->with([
    'market authority' => ['market', 'console'],
    'price authority' => ['price', 1],
    'currency authority' => ['currency', 'USD'],
    'product authority' => ['productId', '01K00000000000000000000000'],
    'variant authority' => ['variantId', '01K00000000000000000000000'],
    'credential bundle' => ['credentials', 'synthetic-value'],
    'empty legacy account password' => ['account_password', ''],
    'legacy account balance' => ['account_balance', 0],
    'price total alias' => ['total', 100],
    'nested credentials' => ['login', ['password' => 'synthetic-value']],
    'unknown supplier authority' => ['supplier_authority', 'console'],
]);

test('the quote request applies the same exact allowlist to JSON input', function () {
    createQuoteCatalog();

    $response = $this->json('GET', '/coins/quote', [
        'platform' => 'pc',
        'quantity' => 50_000,
        'login' => ['password' => 'synthetic-value'],
    ]);

    assertQuoteIsNotStored($response);
    $response->assertUnprocessable();
});

test('missing or ambiguous catalog data fails closed', function (string $failure) {
    if ($failure === 'missing') {
        assertQuoteUnavailable($this->getJson('/coins/quote?platform=pc&quantity=50000'));

        return;
    }

    createQuoteCatalog();
    Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'is_visible' => true,
        'archived_at' => null,
    ]);

    assertQuoteUnavailable($this->getJson('/coins/quote?platform=pc&quantity=50000'));
})->with(['missing', 'ambiguous']);

test('hidden archived or inactive catalog data fails closed', function (string $failure) {
    $catalog = createQuoteCatalog();

    match ($failure) {
        'hidden' => $catalog['product']->update(['is_visible' => false]),
        'archived' => $catalog['product']->update(['archived_at' => now()]),
        'inactive variant' => $catalog['variants']['pc']->update(['is_active' => false]),
    };

    assertQuoteUnavailable($this->getJson('/coins/quote?platform=pc&quantity=50000'));
})->with(['hidden', 'archived', 'inactive variant']);

test('missing ambiguous or malformed pricing data fails closed without a fallback', function (string $failure) {
    createQuoteCatalog(withRules: false);

    if ($failure !== 'missing') {
        PriceRule::create([
            'name' => 'PC pricing',
            'service_type' => ServiceType::Coins,
            'configuration' => $failure === 'malformed'
                ? quoteRuleConfiguration('pc', ['multipliers_basis_points' => []])
                : quoteRuleConfiguration('pc'),
            'is_active' => true,
        ]);
    }

    if ($failure === 'ambiguous') {
        PriceRule::create([
            'name' => 'Duplicate PC pricing',
            'service_type' => ServiceType::Coins,
            'configuration' => quoteRuleConfiguration('pc'),
            'is_active' => true,
        ]);
    }

    assertQuoteUnavailable($this->getJson('/coins/quote?platform=pc&quantity=50000'));
})->with(['missing', 'ambiguous', 'malformed']);
