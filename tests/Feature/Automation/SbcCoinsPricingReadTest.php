<?php

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const SBC_PRICING_READ_PATH = '/api/automation/v1/pricing/coins/sbc-bases';

/** @param array<string, mixed> $changes */
function sbcPricingReadRuleConfiguration(string $group, array $changes = []): array
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
            : ($group === 'console_fast'
                ? [7_000, 7_000, 7_000, 7_000, 7_000, 7_000]
                : [9_000, 9_000, 9_000, 9_000, 9_000, 9_000]);

    return array_replace($configuration, $changes);
}

/** @return array{playstation: ProductVariant, pc: ProductVariant} */
function createSbcPricingReadCatalog(int $priceVersion = 7): array
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'name_ar' => 'كوينز فيفا 27',
        'name_en' => 'FC 27 Coins',
        'is_visible' => true,
        'archived_at' => null,
    ]);

    $playstation = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'price_version' => $priceVersion,
        'is_active' => true,
    ]);
    $pc = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Coins,
        'platform' => Platform::Pc,
        'price_version' => $priceVersion,
        'is_active' => true,
    ]);

    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        PriceRule::query()->create([
            'name' => "SBC pricing read {$group}",
            'service_type' => ServiceType::Coins,
            'configuration' => sbcPricingReadRuleConfiguration($group),
            'is_active' => true,
        ]);
    }

    return compact('playstation', 'pc');
}

function signedSbcPricingRead(
    ?string $signature = null,
    ?string $timestamp = null,
    string $path = SBC_PRICING_READ_PATH,
    string $body = '',
    string $method = 'GET',
    string $key = 'sbc-pricing-read-test-key',
    ?string $configuredKey = null,
) {
    $timestamp ??= (string) now()->timestamp;
    $secret = 'sbc-pricing-read-test-secret';

    config()->set('services.n8n.sbc_pricing_read_key', $configuredKey ?? $key);
    config()->set('services.n8n.sbc_pricing_read_secret', $secret);

    return test()->call(
        $method,
        $path,
        server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ARABUT_KEY' => $key,
            'HTTP_X_ARABUT_TIMESTAMP' => $timestamp,
            'HTTP_X_ARABUT_SIGNATURE' => $signature ?? hash_hmac(
                'sha256',
                $timestamp."\nGET\n".SBC_PRICING_READ_PATH."\n",
                $secret,
            ),
        ],
        content: $body,
    );
}

it('returns only authoritative PS fast and PC one-million Coins bases', function () {
    CarbonImmutable::setTestNow('2026-08-12 12:00:00 UTC');
    createSbcPricingReadCatalog(priceVersion: 7);

    $response = signedSbcPricingRead()
        ->assertOk()
        ->assertExactJson([
            'schemaVersion' => 1,
            'pricingVersion' => 7,
            'pricedAt' => '2026-08-12T12:00:00+00:00',
            'quotes' => [
                'playstation_fast' => [
                    'platform' => Platform::PlayStation->value,
                    'delivery' => DeliveryMode::Fast->value,
                    'quantity' => 1_000_000,
                    'totalHalalah' => 7_000,
                ],
                'pc' => [
                    'platform' => Platform::Pc->value,
                    'delivery' => null,
                    'quantity' => 1_000_000,
                    'totalHalalah' => 9_000,
                ],
            ],
        ]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('rejects a signature for a different route', function () {
    createSbcPricingReadCatalog();
    $timestamp = (string) now()->timestamp;
    $wrongRouteSignature = hash_hmac(
        'sha256',
        $timestamp."\nGET\n/api/automation/v1/pricing/coins/other\n",
        'sbc-pricing-read-test-secret',
    );

    signedSbcPricingRead(signature: $wrongRouteSignature, timestamp: $timestamp)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_signature')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('rejects stale requests and all query or body input', function (string $path, string $body, int $status, string $code) {
    createSbcPricingReadCatalog();
    $timestamp = $status === 409
        ? (string) now()->subMinutes(6)->timestamp
        : (string) now()->timestamp;

    signedSbcPricingRead(timestamp: $timestamp, path: $path, body: $body)
        ->assertStatus($status)
        ->assertJsonPath('error.code', $code);
})->with([
    'stale timestamp' => [SBC_PRICING_READ_PATH, '', 409, 'stale_sbc_pricing_read'],
    'query string' => [SBC_PRICING_READ_PATH.'?currency=USD', '', 422, 'invalid_sbc_pricing_read'],
    'request body' => [SBC_PRICING_READ_PATH, '{}', 422, 'invalid_sbc_pricing_read'],
]);

it('fails closed when the current Coins catalog or required rule state is unavailable', function (string $case) {
    $catalog = createSbcPricingReadCatalog();

    if ($case === 'missing-rule') {
        PriceRule::query()
            ->where('configuration->group', 'pc')
            ->delete();
    } elseif ($case === 'mismatched-variant-version') {
        $catalog['pc']->update(['price_version' => 8]);
    } else {
        $catalog['playstation']->update(['price_version' => 0]);
        $catalog['pc']->update(['price_version' => 0]);
    }

    signedSbcPricingRead()
        ->assertServiceUnavailable()
        ->assertExactJson([
            'error' => [
                'code' => 'sbc_pricing_unavailable',
                'message' => 'The current Coins pricing bases are unavailable.',
            ],
        ]);
})->with(['missing-rule', 'mismatched-variant-version', 'nonpositive-variant-version']);

it('rate limits the signed machine-readable pricing boundary', function () {
    createSbcPricingReadCatalog();
    $key = 'sbc-pricing-rate-limit-test-key';

    foreach (range(1, 10) as $attempt) {
        signedSbcPricingRead(key: $key)->assertOk();
    }

    $response = signedSbcPricingRead(key: $key)
        ->assertTooManyRequests();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('accepts only the exact GET method', function (string $method) {
    createSbcPricingReadCatalog();

    signedSbcPricingRead(method: $method)
        ->assertMethodNotAllowed();
})->with(['HEAD', 'POST']);

it('authenticates before charging the trusted credential rate-limit bucket', function () {
    createSbcPricingReadCatalog();
    $key = 'sbc-pricing-auth-order-test-key';

    foreach (range(1, 10) as $attempt) {
        signedSbcPricingRead(signature: str_repeat((string) ($attempt % 10), 64), key: $key)
            ->assertUnauthorized();
    }

    foreach (range(1, 10) as $attempt) {
        signedSbcPricingRead(key: $key)->assertOk();
    }

    $limited = signedSbcPricingRead(key: $key)
        ->assertTooManyRequests();
    $invalidAfterLimit = signedSbcPricingRead(signature: str_repeat('f', 64), key: $key)
        ->assertUnauthorized();
    $rotatedInvalidAfterLimit = signedSbcPricingRead(
        signature: str_repeat('e', 64),
        key: 'rotated-invalid-key',
        configuredKey: $key,
    )->assertUnauthorized();

    expect($limited->headers->get('Cache-Control'))->toContain('no-store')
        ->and($invalidAfterLimit->headers->get('Cache-Control'))->toContain('no-store')
        ->and($rotatedInvalidAfterLimit->headers->get('Cache-Control'))->toContain('no-store');
});
