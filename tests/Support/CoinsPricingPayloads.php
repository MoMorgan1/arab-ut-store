<?php

declare(strict_types=1);

/**
 * Payload builders for the Coins pricing contract.
 *
 * These live outside the test files that use them because Pest executes a test
 * file's top-level it() blocks on include, so requiring one test file from
 * another would run its whole suite a second time.
 *
 * The snapshot is shaped exactly the way the n8n workflow builds one, at the
 * grain it publishes, so the live contract is exercised rather than a
 * convenient approximation of it.
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
            // The workflow writes one of these wherever whole-grain rounding
            // would otherwise let the price descend. They are displayed prices
            // on the 0.1-SAR grain, which is the shape the contract requires.
            'exact_overrides_halalah' => ['55000' => 1_240, '155000' => 3_480],
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

/**
 * The same snapshot the workflow publishes, but carrying the eleven anchors
 * instead of their expansion.
 *
 * @param  array<int, int>|null  $anchors
 * @return array<string, mixed>
 */
function n8nAnchoredSnapshot(int $declaredMinimum = 50_000, ?array $anchors = null): array
{
    $payload = n8nSnapshot(increment: 5_000);
    $anchors ??= UPLIFT_ANCHORS;

    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        unset($payload['rules'][$group]['multipliers_basis_points']);
        $payload['rules'][$group]['multiplier_anchors_basis_points'] = $anchors;
        $payload['legalRanges'][$group]['minimum'] = $declaredMinimum;
    }

    return $payload;
}
