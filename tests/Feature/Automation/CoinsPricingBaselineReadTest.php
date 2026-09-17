<?php

use App\Models\PriceRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const PRICING_BASELINE_PATH = '/api/automation/v1/pricing/coins/baseline';
const PRICING_BASELINE_SECRET = 'pricing-baseline-test-secret';

function signedBaselineRead(
    ?string $signature = null,
    ?string $timestamp = null,
    string $path = PRICING_BASELINE_PATH,
    string $method = 'GET',
    string $body = '',
) {
    $timestamp ??= (string) now()->timestamp;

    config()->set('services.n8n.pricing_secret', PRICING_BASELINE_SECRET);

    return test()->call(
        $method,
        $path,
        server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ARABUT_TIMESTAMP' => $timestamp,
            'HTTP_X_ARABUT_SIGNATURE' => $signature ?? hash_hmac(
                'sha256',
                $timestamp."\nGET\n".PRICING_BASELINE_PATH."\n",
                PRICING_BASELINE_SECRET,
            ),
        ],
        content: $body,
    );
}

/** @param array<string, mixed> $overrides */
function appliedRun(array $overrides = [], string $runId = '01M2QQ945KRHD19E2XQMNEB3FS', int $version = 7): PriceRun
{
    return PriceRun::query()->create(array_replace([
        'run_id' => $runId,
        'event_id' => strrev($runId),
        'status' => 'applied',
        'mode' => 'apply',
        'pricing_version' => $version,
        'payload' => [
            'rules' => [
                'console_normal' => ['flat_rate_halalah_per_million' => 72_861],
                'console_fast' => ['tier_rates_halalah_per_million' => [168_050, 168_050, 172_254, 176_500, 180_000, 184_000]],
                'pc' => ['tier_rates_halalah_per_million' => [1_777, 1_836, 1_836, 2_132, 3_968, 3_968]],
            ],
            'observations' => ['cyclePSUsdPerM' => 150.1, 'cyclePCUsdPerM' => null],
        ],
        'started_at' => now(),
        'completed_at' => now(),
    ], $overrides));
}

it('hands back what the last applied run published', function () {
    // n8n gives a MANUAL execution empty workflow memory, so the run that
    // carries prices forward has nothing of its own to carry and reported a
    // healthy workflow as broken. The store knows what it last published.
    appliedRun();

    signedBaselineRead()
        ->assertOk()
        ->assertJsonPath('schemaVersion', 1)
        ->assertJsonPath('runId', '01M2QQ945KRHD19E2XQMNEB3FS')
        ->assertJsonPath('pricingVersion', 7)
        ->assertJsonPath('rates.console_normal', 72_861)
        ->assertJsonPath('rates.console_fast.0', 168_050)
        ->assertJsonPath('rates.pc.5', 3_968)
        ->assertJsonPath('cyclePSUsdPerM', 150.1)
        ->assertJsonPath('cyclePCUsdPerM', null);
});

it('reads the newest applied run, not the newest run', function () {
    appliedRun(version: 7);
    appliedRun(['status' => 'proposed', 'payload' => [
        'rules' => ['console_normal' => ['flat_rate_halalah_per_million' => 999_999]],
    ]], runId: '01M2QQ945KRHD19E2XQMNEB3FT', version: 8);

    signedBaselineRead()->assertOk()->assertJsonPath('rates.console_normal', 72_861);
});

it('says a store that never published has no baseline, rather than inventing one', function () {
    signedBaselineRead()
        ->assertNotFound()
        ->assertJsonPath('error.code', 'coins_pricing_baseline_unavailable');
});

it('refuses a run whose rates are missing or malformed', function (array $rules) {
    appliedRun(['payload' => ['rules' => $rules, 'observations' => []]]);

    signedBaselineRead()->assertNotFound();
})->with([
    'no console rate' => [['console_fast' => ['tier_rates_halalah_per_million' => [1]], 'pc' => ['tier_rates_halalah_per_million' => [1]]]],
    'an empty tier list' => [[
        'console_normal' => ['flat_rate_halalah_per_million' => 1],
        'console_fast' => ['tier_rates_halalah_per_million' => []],
        'pc' => ['tier_rates_halalah_per_million' => [1]],
    ]],
    'a zero rate' => [[
        'console_normal' => ['flat_rate_halalah_per_million' => 1],
        'console_fast' => ['tier_rates_halalah_per_million' => [0]],
        'pc' => ['tier_rates_halalah_per_million' => [1]],
    ]],
]);

it('refuses an unsigned read', function () {
    appliedRun();

    signedBaselineRead(signature: str_repeat('a', 64))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_signature');
});

it('refuses a signature minted for another route', function () {
    appliedRun();
    $timestamp = (string) now()->timestamp;
    $otherRoute = hash_hmac(
        'sha256',
        $timestamp."\nGET\n/api/automation/v1/pricing/coins/sbc-bases\n",
        PRICING_BASELINE_SECRET,
    );

    signedBaselineRead(signature: $otherRoute, timestamp: $timestamp)
        ->assertUnauthorized();
});

it('refuses a stale read', function () {
    appliedRun();

    signedBaselineRead(timestamp: (string) now()->subMinutes(10)->timestamp)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'stale_baseline_read');
});

it('refuses anything but a bare GET', function () {
    appliedRun();

    signedBaselineRead(method: 'POST')->assertStatus(405);
    signedBaselineRead(path: PRICING_BASELINE_PATH.'?group=pc')->assertStatus(422);
});

it('never lets a baseline be cached', function () {
    appliedRun();

    expect(signedBaselineRead()->headers->get('Cache-Control'))->toContain('no-store');
});
