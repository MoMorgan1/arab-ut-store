<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\PriceRule;
use App\Models\PriceRun;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function coinsPricingRunPayload(array $changes = []): array
{
    $payload = [
        'schemaVersion' => 1,
        'eventId' => (string) Str::ulid(),
        'runId' => (string) Str::ulid(),
        'generatedAt' => now()->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
        'mode' => 'dry_run',
        'serviceType' => 'coins',
        'legalRanges' => [
            'console_normal' => ['minimum' => 50_000, 'maximum' => 2_000_000, 'increment' => 5_000],
            'console_fast' => ['minimum' => 50_000, 'maximum' => 20_000_000, 'increment' => 5_000],
            'pc' => ['minimum' => 50_000, 'maximum' => 20_000_000, 'increment' => 5_000],
        ],
        'rules' => [
            'console_normal' => coinsPricingRuleConfiguration('console_normal'),
            'console_fast' => coinsPricingRuleConfiguration('console_fast'),
            'pc' => coinsPricingRuleConfiguration('pc'),
        ],
        'observations' => [
            'source' => 'fft+utt',
            'ratioEuroUsd' => 1.1,
        ],
    ];

    return array_replace_recursive($payload, $changes);
}

/** @return array<string, mixed> */
function coinsPricingRuleConfiguration(string $group): array
{
    $configuration = [
        'version' => 1,
        'group' => $group,
        'tier_upper_bounds_k' => [1000, 2000, 5000, 10000, 15000],
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ];

    $configuration[$group === 'console_normal'
        ? 'flat_rate_halalah_per_million'
        : 'tier_rates_halalah_per_million'] = $group === 'console_normal'
            ? 5_000
            : [5_000, 5_500, 6_000, 6_500, 7_000, 7_500];

    return $configuration;
}

function signedCoinsPricingRun(array $payload, ?string $signature = null, bool $configureCredentials = true)
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp = (string) now()->timestamp;
    $event = (string) $payload['eventId'];
    $secret = 'pricing-test-secret';

    if ($configureCredentials) {
        config()->set('services.n8n.pricing_key', 'pricing-test-key');
        config()->set('services.n8n.pricing_secret', $secret);
    }

    return test()->call(
        'POST',
        '/api/automation/v1/pricing/coins/runs',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ARABUT_KEY' => 'pricing-test-key',
            'HTTP_X_ARABUT_TIMESTAMP' => $timestamp,
            'HTTP_X_ARABUT_EVENT' => $event,
            'HTTP_X_ARABUT_SIGNATURE' => $signature ?? hash_hmac(
                'sha256',
                $timestamp."\n".$event."\n".$body,
                $secret,
            ),
        ],
        content: $body,
    );
}

it('accepts a signed dry-run without changing active Coins rules', function () {
    PriceRule::create([
        'name' => 'Existing normal rule',
        'service_type' => ServiceType::Coins,
        'configuration' => coinsPricingRuleConfiguration('console_normal'),
        'is_active' => true,
    ]);

    $response = signedCoinsPricingRun(coinsPricingRunPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'proposed');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(PriceRun::sole()->mode)->toBe('dry_run')
        ->and(PriceRule::where('is_active', true)->count())->toBe(1);
});

it('applies three groups atomically and bumps active Coins variant versions', function () {
    $variant = ProductVariant::factory()->create([
        'service_type' => ServiceType::Coins,
        'platform' => Platform::Pc,
        'price_version' => 4,
        'is_active' => true,
    ]);

    $payload = coinsPricingRunPayload(['mode' => 'apply']);

    signedCoinsPricingRun($payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'applied')
        ->assertJsonPath('data.pricingVersion', 5);

    expect(PriceRun::sole()->mode)->toBe('apply')
        ->and(PriceRule::where('service_type', ServiceType::Coins)->where('is_active', true)->count())->toBe(3)
        ->and($variant->fresh()->price_version)->toBe(5);
});

it('stores the multiplier curve without the entries that only repeat the one before', function () {
    // The pricing run publishes one entry per legal quantity - thousands of them,
    // most identical to their neighbour. The lookup answers with the last entry at
    // or below the quantity, so the repeats are already implied. Keeping them would
    // mean writing the same number twice per run, forever: once into the run's own
    // payload and once into the rule every storefront request reads.
    $repetitive = ['50000' => 10_000, '55000' => 10_000, '60000' => 10_500, '65000' => 10_500];
    $payload = coinsPricingRunPayload(['mode' => 'apply']);

    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        $payload['rules'][$group]['multipliers_basis_points'] = $repetitive;
    }

    signedCoinsPricingRun($payload)->assertCreated();

    $stored = PriceRule::query()
        ->where('service_type', ServiceType::Coins)
        ->where('is_active', true)
        ->get()
        ->map(fn (PriceRule $rule): array => $rule->configuration['multipliers_basis_points']);

    expect($stored)->each->toBe(['50000' => 10_000, '60000' => 10_500])
        ->and(PriceRun::sole()->payload['rules']['pc']['multipliers_basis_points'])
        ->toBe(['50000' => 10_000, '60000' => 10_500]);
});
it('rejects a malformed or incomplete rules payload before writing', function (string $case) {
    $payload = coinsPricingRunPayload();

    if ($case === 'missing-pc') {
        unset($payload['rules']['pc']);
    } else {
        $payload['rules']['pc']['tier_upper_bounds_k'] = [1000, 500, 5000, 10000, 15000];
    }

    signedCoinsPricingRun($payload)
        ->assertUnprocessable();

    expect(PriceRun::count())->toBe(0)
        ->and(PriceRule::count())->toBe(0);
})->with(['missing-pc', 'invalid-tier-order']);

it('rejects invalid signatures and replays without changing pricing state', function () {
    $payload = coinsPricingRunPayload(['mode' => 'apply']);

    signedCoinsPricingRun($payload, str_repeat('0', 64))
        ->assertUnauthorized();

    signedCoinsPricingRun($payload)
        ->assertCreated();

    signedCoinsPricingRun($payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'coins_pricing_run_replayed');

    expect(PriceRun::count())->toBe(1);
});

it('rejects a duplicate signed event with a different run id', function () {
    $first = coinsPricingRunPayload();
    $second = coinsPricingRunPayload([
        'eventId' => $first['eventId'],
        'runId' => (string) Str::ulid(),
    ]);

    signedCoinsPricingRun($first)->assertCreated();

    expect(PriceRun::sole()->event_id)->toBe($first['eventId']);

    signedCoinsPricingRun($second)
        ->assertConflict()
        ->assertJsonPath('error.code', 'coins_pricing_event_replayed');

    expect(PriceRun::count())->toBe(1);
});
