<?php

use App\Enums\ServiceType;
use App\Models\PriceRule;
use App\Models\PriceRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The pricing run lives in n8n, outside this repository, so nothing here fails
 * when the two drift apart - the storefront just stops getting new prices, and
 * the only signal is a 422 an hour later. This builds a snapshot shaped exactly
 * the way that workflow builds one, at the grain it publishes, and requires the
 * live contract to accept it.
 */
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

    $message = (string) collect((array) $response->json('errors'))
        ->flatten()
        ->first(fn (string $error): bool => str_contains($error, 'increment'));

    // Named as its own field, not as a loose "5000" - which the 50,000 minimum
    // already contains, and would have passed a message that never mentioned
    // the increment at all.
    expect($message)->toContain('"increment":5000')
        ->and($message)->toContain('"increment":10000')
        ->and(PriceRun::count())->toBe(0)
        ->and(PriceRule::count())->toBe(0);
});
