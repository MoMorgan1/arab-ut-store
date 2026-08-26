<?php

namespace Tests\Feature\Admin;

use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\ProductAuthority;
use App\Enums\UserRole;
use App\Models\CatalogSource;
use App\Models\CatalogSyncItem;
use App\Models\CatalogSyncRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests and nonprivileged accounts cannot access product detail', function (): void {
    $product = Product::factory()->create();

    $this->get("/admin/products/{$product->public_id}")->assertRedirect('/en/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get("/admin/products/{$product->public_id}")->assertForbidden();
    }
});

test('nonexistent product publicId returns 404', function (): void {
    $admin = adminProductDetailActor(UserRole::Admin);

    $this->actingAs($admin)->get('/admin/products/01K5NONEXISTENT00000000000')->assertNotFound();
});

test('confirmed Admin can view manual product detail page with variants and media', function (string $prefix): void {
    $admin = adminProductDetailActor(UserRole::Admin);

    $category = Category::factory()->create(['name_en' => 'FC Coins Category']);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'authority' => ProductAuthority::Manual,
        'name_en' => 'FC 26 Coins PS5 Manual',
        'name_ar' => 'كوينز فيفا 26 يدوي',
        'description_en' => 'Detailed English description',
        'description_ar' => 'وصف عربي مفصل',
        'sort_order' => 5,
        'is_visible' => true,
    ]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'sku' => 'SKU-COINS-PS5-100K',
        'price_halalah' => 3500,
        'sale_price_halalah' => 3000,
        'quantity_k' => 100,
        'is_active' => true,
    ]);

    ProductMedia::create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => 'products/coins.png',
        'alt_en' => 'Coins Cover',
        'alt_ar' => 'غلاف الكوينز',
        'sort_order' => 1,
    ]);

    $recordAudit = app(RecordStaffAudit::class);
    $recordAudit->execute(
        $admin,
        $product,
        new StaffAuditEvent('products.updated', ['product_changed' => ['sort_order']], null),
    );

    $path = "{$prefix}/products/{$product->public_id}";

    $this->actingAs($admin)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/products/show', false)
            ->where('auth', null)
            ->where('product.id', (string) $product->public_id)
            ->where('product.nameEn', 'FC 26 Coins PS5 Manual')
            ->where('product.authority', 'manual')
            ->where('product.isEditable', true)
            ->where('product.isVisible', true)
            ->where('product.sortOrder', 5)
            ->has('product.variants', 1)
            ->where('product.variants.0.sku', 'SKU-COINS-PS5-100K')
            ->where('product.variants.0.price.amountMinor', '3500')
            ->where('product.variants.0.salePrice.amountMinor', '3000')
            ->has('product.media', 1)
            ->where('product.media.0.path', 'products/coins.png')
            ->has('product.recentAuditLogs', 1)
            // The write URL stays inside the route family the page was reached
            // through, the same way the team and contact URLs do.
            ->where('updateUrl', "{$prefix}/api/products/{$product->public_id}")
        );
})->with(['/admin', '/en/admin']);

test('confirmed Admin can view automation product with sync run info', function (): void {
    $admin = adminProductDetailActor(UserRole::Admin);

    $source = CatalogSource::factory()->create(['name' => 'Alpha Sync Provider']);
    $product = Product::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'ALPHA-SBC-001',
        'authority' => ProductAuthority::Automation,
        'name_en' => 'Automated SBC Package',
    ]);

    $syncRun = CatalogSyncRun::create([
        'source_id' => $source->id,
        'run_id' => 'RUN-2026-08-23-001',
        'status' => 'completed',
        'is_complete_snapshot' => true,
        'source_count' => 10,
        'applied_count' => 10,
        'held_count' => 0,
        'failed_count' => 0,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);

    CatalogSyncItem::create([
        'catalog_sync_run_id' => $syncRun->id,
        'product_id' => $product->id,
        'external_id' => 'EXT-12345',
        'outcome' => 'applied',
        'payload' => ['title' => 'Automated SBC Package'],
    ]);

    $this->actingAs($admin)
        ->get("/admin/products/{$product->public_id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.authority', 'automation')
            ->where('product.isEditable', false)
            ->where('product.automation.runId', 'RUN-2026-08-23-001')
            ->where('product.automation.status', 'completed')
            ->where('product.automation.outcome', 'applied')
        );
});

function adminProductDetailActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINPRODUCTDETAILSTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
