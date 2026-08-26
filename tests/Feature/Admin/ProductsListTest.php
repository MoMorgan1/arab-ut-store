<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\CatalogSource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests and nonprivileged accounts cannot access admin products list', function (): void {
    $this->get('/admin/products')->assertRedirect('/en/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get('/admin/products')->assertForbidden();
    }

    $inactiveStaff = adminProductsActor(UserRole::Staff);
    $inactiveStaff->forceFill(['is_active' => false])->save();
    $this->actingAs($inactiveStaff)->get('/admin/products')->assertForbidden();
});

test('staff users without catalog.view permission are forbidden from products list', function (): void {
    $staff = adminProductsActor(UserRole::Staff);

    $this->actingAs($staff)->get('/admin/products')->assertForbidden();
});

test('unconfirmed MFA admin users are redirected to MFA setup', function (): void {
    $admin = adminProductsActor(UserRole::Admin);
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin)->get('/admin/products')->assertRedirect('/admin/settings');
});

test('confirmed Admin can view products list with Inertia payload', function (string $path): void {
    $admin = adminProductsActor(UserRole::Admin);

    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'authority' => ProductAuthority::Manual,
        'name_en' => 'Test Coins Product',
        'slug' => 'test-coins-product',
        'is_visible' => true,
    ]);
    ProductVariant::factory()->count(2)->create([
        'product_id' => $product->id,
    ]);

    $this->actingAs($admin)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/products/index', false)
            ->where('auth', null)
            ->where('locale', 'en')
            ->where('direction', 'ltr')
            // The catalog is never empty: 2026_08_16_000003 provisions the
            // fut-champions and division-rivals manual service products, so
            // search for the row under test rather than assuming it is alone.
            ->has('products', 3)
            ->has('pagination')
            ->has('filters')
            ->has('filterOptions')
            ->where('products', fn (Collection $products) => $products
                ->contains(fn (array $row) => $row['name'] === 'Test Coins Product'
                    && $row['variantsCount'] === 2
                    && $row['authority'] === 'manual'))
        );
})->with(['/admin/products', '/en/admin/products']);

test('products list can search by name, slug, and variant SKU', function (): void {
    $admin = adminProductsActor(UserRole::Admin);

    $product1 = Product::factory()->create([
        'name_en' => 'FC 26 Coins Pack',
        'slug' => 'fc-26-coins-pack',
    ]);
    ProductVariant::factory()->create([
        'product_id' => $product1->id,
        'sku' => 'SKU-FC26-100K',
    ]);

    $product2 = Product::factory()->create([
        'name_en' => 'SBC Solving Service',
        'slug' => 'sbc-solving-service',
    ]);
    ProductVariant::factory()->create([
        'product_id' => $product2->id,
        'sku' => 'SKU-SBC-EXPERT',
    ]);

    // Search by product name
    $this->actingAs($admin)
        ->get('/admin/products?search=Coins')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products', 1)
            ->where('products.0.slug', 'fc-26-coins-pack')
        );

    // Search by slug
    $this->actingAs($admin)
        ->get('/admin/products?search=sbc-solving')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products', 1)
            ->where('products.0.slug', 'sbc-solving-service')
        );

    // Search by variant SKU
    $this->actingAs($admin)
        ->get('/admin/products?search=SKU-FC26')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products', 1)
            ->where('products.0.slug', 'fc-26-coins-pack')
        );
});

test('products list can filter by authority, service type, visibility, and source', function (): void {
    $admin = adminProductsActor(UserRole::Admin);

    $source = CatalogSource::factory()->create(['key' => 'provider_beta', 'name' => 'Provider Beta']);

    $manualProduct = Product::factory()->create([
        'authority' => ProductAuthority::Manual,
        'service_type' => ServiceType::Coins,
        'source_id' => null,
        'is_visible' => true,
    ]);

    $autoProduct = Product::factory()->create([
        'authority' => ProductAuthority::Automation,
        'service_type' => ServiceType::Sbc,
        'source_id' => $source->id,
        'external_id' => 'BETA-SBC-001',
        'is_visible' => false,
    ]);

    // Authority filter. The two provisioned service products are also manual,
    // so the filter narrows to three rows, not one - what matters is that the
    // automation row is excluded and the manual row under test is present.
    $this->actingAs($admin)
        ->get('/admin/products?authority=manual')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products', 3)
            ->where('products', fn (Collection $products) => $products
                ->contains(fn (array $row) => $row['id'] === (string) $manualProduct->public_id)
                && $products->doesntContain(fn (array $row) => $row['id'] === (string) $autoProduct->public_id))
        );

    // Visibility filter
    $this->actingAs($admin)
        ->get('/admin/products?visibility=hidden')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products', 1)
            ->where('products.0.id', (string) $autoProduct->public_id)
        );

    // Source filter
    $this->actingAs($admin)
        ->get('/admin/products?source=provider_beta')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products', 1)
            ->where('products.0.id', (string) $autoProduct->public_id)
        );
});

test('unknown query parameters trigger validation error in after hook', function (): void {
    $admin = adminProductsActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/products?hacked_param=malicious')
        ->assertSessionHasErrors('query');
});

test('products list executes within query budget without N+1 queries', function (): void {
    $admin = adminProductsActor(UserRole::Admin);

    $category = Category::factory()->create();
    $source = CatalogSource::factory()->create();

    // (source_id, external_id) is unique, so each row needs its own identity.
    $products = Product::factory()
        ->count(10)
        ->sequence(fn ($sequence) => ['external_id' => 'BUDGET-'.$sequence->index])
        ->create([
            'category_id' => $category->id,
            'source_id' => $source->id,
        ]);

    foreach ($products as $product) {
        ProductVariant::factory()->count(3)->create(['product_id' => $product->id]);
    }

    DB::enableQueryLog();

    $this->actingAs($admin)
        ->get('/admin/products')
        ->assertOk();

    $queries = DB::getQueryLog();
    // Paginator count + paginator query + catalog sources fetch for filterOptions <= 10 queries total
    expect(count($queries))->toBeLessThanOrEqual(10);
});

function adminProductsActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINPRODUCTSTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
