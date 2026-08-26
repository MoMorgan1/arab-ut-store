<?php

use App\Enums\ServiceType;
use App\Models\PriceRule;
use App\Models\PriceRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The pricing run lives in n8n, outside this repository, so nothing here fails
 * when the two drift apart - the storefront just stops getting new prices, and
 * the only signal is a 422 an hour later. This builds a snapshot shaped exactly
 * the way that workflow builds one, at the grain it publishes, and requires the
 * live contract to accept it.
 */
const UPLIFT_ANCHORS = [
    50_000 => 11_000, 100_000 => 10_600, 150_000 => 10_500, 250_000 => 10_300,
    500_000 => 10_200, 1_000_000 => 10_000, 2_000_000 => 10_150,
    5_000_000 => 10_250, 10_000_000 => 10_350, 15_000_000 => 10_400,
    20_000_000 => 10_500,
];

/** @return array<string, int> */
function n8nMultiplierMap(int $minimum, int $maximum, int $increment): array
{
    $quantities = array_keys(UPLIFT_ANCHORS);
    $map = [];

    for ($quantity = $minimum; $quantity <= $maximum; $quantity += $increment) {
        foreach ($quantities as $index => $right) {
            if ($quantity > $right) {
                continue;
            }

            $left = $index === 0 ? $right : $quantities[$index - 1];
            $map[(string) $quantity] = $left === $right
                ? UPLIFT_ANCHORS[$right]
                : (int) round(UPLIFT_ANCHORS[$left]
                    + (UPLIFT_ANCHORS[$right] - UPLIFT_ANCHORS[$left])
                        * (($quantity - $left) / ($right - $left)));

            continue 2;
        }

        $map[(string) $quantity] = UPLIFT_ANCHORS[20_000_000];
    }

    return $map;
}

/** @return array<string, mixed> */
function n8nSnapshot(int $increment): array
{
    $ranges = [
        'console_normal' => ['minimum' => 50_000, 'maximum' => 2_000_000, 'increment' => $increment],
        'console_fast' => ['minimum' => 50_000, 'maximum' => 20_000_000, 'increment' => $increment],
        'pc' => ['minimum' => 50_000, 'maximum' => 20_000_000, 'increment' => $increment],
    ];
    $rules = [];

    foreach ($ranges as $group => $range) {
        $rule = [
            'version' => 1,
            'group' => $group,
            'tier_upper_bounds_k' => [1_000, 2_000, 5_000, 10_000, 15_000],
            'multipliers_basis_points' => n8nMultiplierMap(
                $range['minimum'],
                $range['maximum'],
                $range['increment'],
            ),
            'service_fee_halalah' => 300,
            'discount_divisor_basis_points' => 10_000,
            'exact_overrides_halalah' => [],
        ];

        if ($group === 'console_normal') {
            $rule['flat_rate_halalah_per_million'] = 19_800;
        } else {
            $rule['tier_rates_halalah_per_million'] = [21_000, 21_500, 22_000, 22_500, 23_000, 23_500];
        }

        $rules[$group] = $rule;
    }

    return [
        'schemaVersion' => 1,
        'eventId' => (string) Str::ulid(),
        'runId' => (string) Str::ulid(),
        'generatedAt' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
        'mode' => 'apply',
        'serviceType' => 'coins',
        'legalRanges' => $ranges,
        'rules' => $rules,
        'observations' => ['source' => 'fft+utt-v2', 'ratioEuroUsd' => 1.1],
    ];
}

function postN8nSnapshot(array $payload)
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp = (string) now()->timestamp;
    $secret = 'pricing-test-secret';

    config()->set('services.n8n.pricing_key', 'pricing-test-key');
    config()->set('services.n8n.pricing_secret', $secret);

    return test()->call(
        'POST',
        '/api/automation/v1/pricing/coins/runs',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ARABUT_KEY' => 'pricing-test-key',
            'HTTP_X_ARABUT_TIMESTAMP' => $timestamp,
            'HTTP_X_ARABUT_EVENT' => (string) $payload['eventId'],
            'HTTP_X_ARABUT_SIGNATURE' => hash_hmac(
                'sha256',
                $timestamp."\n".$payload['eventId']."\n".$body,
                $secret,
            ),
        ],
        content: $body,
    );
}

it('accepts a snapshot shaped exactly the way the pricing run publishes one', function () {
    $payload = n8nSnapshot(increment: 5_000);

    expect(count($payload['rules']['console_fast']['multipliers_basis_points']))->toBe(3_991);

    postN8nSnapshot($payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'applied');

    // And the curve it stored is the collapsed one, priced identically.
    $stored = PriceRule::query()
        ->where('service_type', ServiceType::Coins)
        ->where('is_active', true)
        ->get()
        ->firstWhere(fn (PriceRule $rule): bool => $rule->configuration['group'] === 'console_fast');

    expect(count($stored->configuration['multipliers_basis_points']))
        ->toBeLessThan(1_000)
        ->and(PriceRun::sole()->status)->toBe('applied');
});

it('refuses the grain the pricing run publishes today, and says which one it wanted', function () {
    // This is the failure the owner will see every hour until n8n is updated.
    // It must name the number, or the only way to diagnose it is to read source.
    $response = postN8nSnapshot(n8nSnapshot(increment: 10_000))
        ->assertUnprocessable();

    expect(json_encode($response->json()))->toContain('5000')
        ->and(PriceRun::count())->toBe(0)
        ->and(PriceRule::count())->toBe(0);
});
