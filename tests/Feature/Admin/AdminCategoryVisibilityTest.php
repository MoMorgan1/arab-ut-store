<?php

use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\CatalogSource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

function categoryVisibilityActor(UserRole $role = UserRole::Admin): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create(['role' => $role, 'password' => 'SecurePassword!12']);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

function testCategory(bool $withAutomationSource = true): Category
{
    $source = $withAutomationSource
        ? CatalogSource::factory()->create(['key' => 'n8n-categories'])
        : null;

    $category = Category::factory()->create([
        'source_id' => $source?->id,
        // The catalog tables enforce that source_id and external_id are set
        // together (the source_identity triggers in create_catalog_tables).
        'external_id' => $source === null ? null : 'sbc-'.fake()->unique()->slug(2),
        'is_visible' => true,
        'admin_hidden_at' => null,
    ]);

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'authority' => ProductAuthority::Automation,
        'service_type' => ServiceType::Sbc,
        'is_visible' => true,
        'admin_hidden_at' => null,
        'archived_at' => null,
    ]);

    ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);

    return $category;
}

function hideCategory(User $actor, Category $category, bool $hidden = true, ?bool $expected = null)
{
    return test()
        ->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('admin.categories.visibility.store', ['publicId' => $category->public_id]), [
            'hidden' => $hidden,
            'expected_hidden' => $expected ?? ! $hidden,
        ]);
}

it('hides a category from the storefront and records audit log', function (): void {
    $actor = categoryVisibilityActor();
    $category = testCategory();

    hideCategory($actor, $category)->assertOk()->assertJson(['adminHidden' => true]);

    expect($category->fresh()->admin_hidden_at)->not->toBeNull()
        // Automation columns untouched
        ->and($category->fresh()->is_visible)->toBeTrue();

    $audit = StaffAuditLog::query()->latest('id')->firstOrFail();

    expect($audit->action)->toBe('categories.hidden_from_store')
        ->and($audit->metadata['previous_hidden'])->toBeFalse()
        ->and($audit->metadata['new_hidden'])->toBeTrue()
        ->and($audit->metadata['visible_product_count'])->toBe(1);
});

it('cascades category hiding to exclude products under it from storefront catalog', function (): void {
    $actor = categoryVisibilityActor();
    $category = testCategory();
    $product = Product::query()->where('category_id', $category->id)->firstOrFail();

    $before = Product::query()->storefrontVisible()->whereKey($product->id)->count();
    expect($before)->toBe(1);

    hideCategory($actor, $category)->assertOk();

    // Cascades: category is hidden, so Product::applyStorefrontVisible excludes it
    expect(Product::query()->storefrontVisible()->whereKey($product->id)->count())->toBe(0)
        ->and(Category::query()->storefrontVisible()->whereKey($category->id)->count())->toBe(0);
});

it('restores a hidden category and returns eligible products to the storefront', function (): void {
    $actor = categoryVisibilityActor();
    $category = testCategory();
    $product = Product::query()->where('category_id', $category->id)->firstOrFail();

    hideCategory($actor, $category)->assertOk();
    hideCategory($actor, $category, hidden: false, expected: true)->assertOk()
        ->assertJson(['adminHidden' => false]);

    expect($category->fresh()->admin_hidden_at)->toBeNull()
        ->and(Category::query()->storefrontVisible()->whereKey($category->id)->count())->toBe(1)
        ->and(Product::query()->storefrontVisible()->whereKey($product->id)->count())->toBe(1);

    $audit = StaffAuditLog::query()->latest('id')->firstOrFail();
    expect($audit->action)->toBe('categories.restored_to_store');
});

it('survives an n8n catalog sync snapshot', function (): void {
    $actor = categoryVisibilityActor();
    $category = testCategory();
    $product = Product::query()->where('category_id', $category->id)->firstOrFail();

    hideCategory($actor, $category)->assertOk();

    // Simulating n8n catalog sync rewriting is_visible
    $category->forceFill(['is_visible' => true])->save();

    expect($category->fresh()->admin_hidden_at)->not->toBeNull()
        ->and(Category::query()->storefrontVisible()->whereKey($category->id)->count())->toBe(0)
        ->and(Product::query()->storefrontVisible()->whereKey($product->id)->count())->toBe(0);
});

it('returns 409 when the visibility moved underneath the caller', function (): void {
    $actor = categoryVisibilityActor();
    $category = testCategory();

    hideCategory($actor, $category)->assertOk();

    hideCategory($actor, $category, hidden: true, expected: false)
        ->assertStatus(409)
        ->assertJson(['current' => ['adminHidden' => true]]);
});

it('refuses a staff user without catalog.manage permission', function (): void {
    $actor = categoryVisibilityActor(UserRole::Staff);
    $category = testCategory();

    hideCategory($actor, $category)->assertForbidden();

    expect($category->fresh()->admin_hidden_at)->toBeNull();
});

it('refuses an inactive admin', function (): void {
    $actor = categoryVisibilityActor();
    $actor->forceFill(['is_active' => false])->save();
    $category = testCategory();

    hideCategory($actor, $category)->assertForbidden();
});

it('rejects unknown fields in payload', function (): void {
    $actor = categoryVisibilityActor();
    $category = testCategory();

    test()->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('admin.categories.visibility.store', ['publicId' => $category->public_id]), [
            'hidden' => true,
            'expected_hidden' => false,
            'is_visible' => false,
        ])
        ->assertStatus(422);
});
