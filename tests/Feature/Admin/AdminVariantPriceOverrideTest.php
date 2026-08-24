<?php

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

function priceOverrideActor(UserRole $role = UserRole::Admin): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create(['role' => $role, 'password' => 'SecurePassword!12']);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

function overridableVariant(): ProductVariant
{
    $product = Product::factory()->create(['service_type' => ServiceType::Sbc]);

    return ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'price_halalah' => 1250,
        'sale_price_halalah' => null,
        'price_version' => 4,
        'is_active' => true,
        'configuration' => [],
    ]);
}

function setPrice(User $actor, ProductVariant $variant, array $payload)
{
    return test()
        ->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(
            route('admin.variants.price.store', ['publicId' => $variant->public_id]),
            $payload,
        );
}

it('sets an override, bumps the price version and audits it', function (): void {
    $actor = priceOverrideActor();
    $variant = overridableVariant();

    setPrice($actor, $variant, ['price_halalah' => 900, 'expected_price_version' => 4])
        ->assertOk()
        ->assertJson(['effectivePriceHalalah' => 900, 'hasOverride' => true, 'priceVersion' => 5]);

    $fresh = $variant->fresh();

    expect($fresh->effectivePriceHalalah())->toBe(900)
        // Automation's own columns are untouched, so the next sync reverts nothing.
        ->and($fresh->price_halalah)->toBe(1250)
        ->and($fresh->price_version)->toBe(5);

    $audit = StaffAuditLog::query()->latest('id')->firstOrFail();

    expect($audit->action)->toBe('catalog.price_override_set')
        ->and($audit->metadata['previous_effective_halalah'])->toBe(1250)
        ->and($audit->metadata['new_effective_halalah'])->toBe(900);
});

it('clears an override and bumps the version again', function (): void {
    $actor = priceOverrideActor();
    $variant = overridableVariant();

    setPrice($actor, $variant, ['price_halalah' => 900, 'expected_price_version' => 4])->assertOk();
    setPrice($actor, $variant, ['price_halalah' => null, 'expected_price_version' => 5])
        ->assertOk()
        ->assertJson(['effectivePriceHalalah' => 1250, 'hasOverride' => false, 'priceVersion' => 6]);

    expect($variant->fresh()->admin_price_halalah)->toBeNull();

    expect(StaffAuditLog::query()->latest('id')->firstOrFail()->action)
        ->toBe('catalog.price_override_cleared');
});

it('returns 409 when the variant was repriced underneath the caller', function (): void {
    $actor = priceOverrideActor();
    $variant = overridableVariant();

    setPrice($actor, $variant, ['price_halalah' => 900, 'expected_price_version' => 99])
        ->assertStatus(409)
        ->assertJson(['current' => ['priceVersion' => 4, 'effectivePriceHalalah' => 1250]]);

    expect($variant->fresh()->admin_price_halalah)->toBeNull()
        ->and($variant->fresh()->price_version)->toBe(4);
});

it('refuses a tier table the store could not price with', function (): void {
    $actor = priceOverrideActor();
    $variant = overridableVariant();

    setPrice($actor, $variant, [
        'price_halalah' => 900,
        // First tier must equal the effective price; 500 does not.
        'completion_pricing' => [
            'version' => 1,
            'repeatable' => false,
            'maximum' => 1,
            'tiers' => [['completions' => 1, 'multiplierBps' => 10000, 'totalMinor' => 500]],
        ],
        'expected_price_version' => 4,
    ])->assertStatus(422);

    expect($variant->fresh()->admin_price_halalah)->toBeNull()
        ->and($variant->fresh()->price_version)->toBe(4);
});

it('accepts a tier table the store can price with', function (): void {
    $actor = priceOverrideActor();
    $variant = overridableVariant();

    setPrice($actor, $variant, [
        'price_halalah' => 900,
        'completion_pricing' => [
            'version' => 1,
            'repeatable' => false,
            'maximum' => 1,
            'tiers' => [['completions' => 1, 'multiplierBps' => 10000, 'totalMinor' => 900]],
        ],
        'expected_price_version' => 4,
    ])->assertOk();

    expect($variant->fresh()->admin_completion_pricing)->not->toBeNull();
});

it('refuses a tier table without an override price', function (): void {
    $actor = priceOverrideActor();
    $variant = overridableVariant();

    setPrice($actor, $variant, [
        'price_halalah' => null,
        'completion_pricing' => ['version' => 1],
        'expected_price_version' => 4,
    ])->assertStatus(422);
});

it('refuses a zero or negative price', function (): void {
    $actor = priceOverrideActor();
    $variant = overridableVariant();

    setPrice($actor, $variant, ['price_halalah' => 0, 'expected_price_version' => 4])
        ->assertStatus(422);
});

it('refuses a staff user and an inactive admin', function (): void {
    $variant = overridableVariant();

    setPrice(priceOverrideActor(UserRole::Staff), $variant, [
        'price_halalah' => 900, 'expected_price_version' => 4,
    ])->assertForbidden();

    $inactive = priceOverrideActor();
    $inactive->forceFill(['is_active' => false])->save();

    setPrice($inactive, $variant, ['price_halalah' => 900, 'expected_price_version' => 4])
        ->assertForbidden();

    expect($variant->fresh()->admin_price_halalah)->toBeNull();
});

it('rejects unknown fields', function (): void {
    $actor = priceOverrideActor();
    $variant = overridableVariant();

    setPrice($actor, $variant, [
        'price_halalah' => 900,
        'expected_price_version' => 4,
        'price_halalah_raw' => 1,
    ])->assertStatus(422);
});
