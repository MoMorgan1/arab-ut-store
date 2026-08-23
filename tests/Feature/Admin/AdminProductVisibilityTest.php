<?php

use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\CatalogSource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

function productVisibilityActor(UserRole $role = UserRole::Admin): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create(['role' => $role, 'password' => 'SecurePassword!12']);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

function automationProduct(): Product
{
    $source = CatalogSource::factory()->create(['key' => 'n8n-products']);

    $product = Product::factory()->create([
        'authority' => ProductAuthority::Automation,
        'source_id' => $source->id,
        'external_id' => 'EXT-HIDE-1',
        'service_type' => ServiceType::Sbc,
        'is_visible' => true,
        'archived_at' => null,
    ]);

    ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);

    return $product;
}

function hideProduct(User $actor, Product $product, bool $hidden = true, ?bool $expected = null)
{
    return test()
        ->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('admin.products.visibility.store', ['publicId' => $product->public_id]), [
            'hidden' => $hidden,
            'expected_hidden' => $expected ?? ! $hidden,
        ]);
}

it('hides an automation product from the storefront and records it', function (): void {
    $actor = productVisibilityActor();
    $product = automationProduct();

    hideProduct($actor, $product)->assertOk()->assertJson(['adminHidden' => true]);

    expect($product->fresh()->admin_hidden_at)->not->toBeNull()
        // The columns automation owns are untouched, so the next sync has
        // nothing to fight over and nothing to revert.
        ->and($product->fresh()->is_visible)->toBeTrue()
        ->and($product->fresh()->archived_at)->toBeNull();

    $audit = StaffAuditLog::query()->latest('id')->firstOrFail();

    expect($audit->action)->toBe('products.hidden_from_store')
        ->and($audit->metadata['authority'])->toBe('automation');
});

it('keeps a hidden product out of the storefront catalog', function (): void {
    $actor = productVisibilityActor();
    $product = automationProduct();

    $before = Product::query()->storefrontVisible()->whereKey($product->id)->count();

    hideProduct($actor, $product)->assertOk();

    // storefrontVisible() is the single predicate the catalog reader, the cart
    // adders and checkout all route through, so this covers every buy path.
    expect($before)->toBe(1)
        ->and(Product::query()->storefrontVisible()->whereKey($product->id)->count())->toBe(0);
});

it('restores a hidden product', function (): void {
    $actor = productVisibilityActor();
    $product = automationProduct();

    hideProduct($actor, $product)->assertOk();
    hideProduct($actor, $product, hidden: false, expected: true)->assertOk()
        ->assertJson(['adminHidden' => false]);

    expect($product->fresh()->admin_hidden_at)->toBeNull()
        ->and(Product::query()->storefrontVisible()->whereKey($product->id)->count())->toBe(1);
});

it('survives a snapshot that rewrites the automation columns', function (): void {
    $actor = productVisibilityActor();
    $product = automationProduct();

    hideProduct($actor, $product)->assertOk();

    // Exactly what SyncCatalogSnapshot does to a product on every run.
    $product->forceFill(['is_visible' => true, 'archived_at' => null])->save();

    expect($product->fresh()->admin_hidden_at)->not->toBeNull()
        ->and(Product::query()->storefrontVisible()->whereKey($product->id)->count())->toBe(0);
});

it('returns 409 when the visibility moved underneath the caller', function (): void {
    $actor = productVisibilityActor();
    $product = automationProduct();

    hideProduct($actor, $product)->assertOk();

    hideProduct($actor, $product, hidden: true, expected: false)
        ->assertStatus(409)
        ->assertJson(['current' => ['adminHidden' => true]]);
});

it('refuses a staff user', function (): void {
    $actor = productVisibilityActor(UserRole::Staff);
    $product = automationProduct();

    hideProduct($actor, $product)->assertForbidden();

    expect($product->fresh()->admin_hidden_at)->toBeNull();
});

it('refuses an inactive admin', function (): void {
    $actor = productVisibilityActor();
    $actor->forceFill(['is_active' => false])->save();
    $product = automationProduct();

    hideProduct($actor, $product)->assertForbidden();
});

it('requires a confirmed password', function (): void {
    $actor = productVisibilityActor();
    $product = automationProduct();

    test()->actingAs($actor)
        ->postJson(route('admin.products.visibility.store', ['publicId' => $product->public_id]), [
            'hidden' => true,
            'expected_hidden' => false,
        ])
        ->assertStatus(423);

    expect($product->fresh()->admin_hidden_at)->toBeNull();
});

it('rejects unknown fields', function (): void {
    $actor = productVisibilityActor();
    $product = automationProduct();

    test()->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('admin.products.visibility.store', ['publicId' => $product->public_id]), [
            'hidden' => true,
            'expected_hidden' => false,
            'is_visible' => false,
        ])
        ->assertStatus(422);
});
