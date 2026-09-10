<?php

use App\Enums\ProductAuthority;
use App\Enums\UserRole;
use App\Models\Coupon;
use App\Models\Product;
use App\Support\PublicHandle\CouponHandle;
use App\Support\PublicHandle\ProductHandle;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

test('the catalog handle route patterns accept slugs, codes, and ulids', function (): void {
    expect(ProductHandle::routePattern())->toBe('[0-9A-Za-z-]+')
        ->and(CouponHandle::routePattern())->toBe('[0-9A-Za-z-]+')
        ->and(ProductHandle::isUlid((string) Str::ulid()))->toBeTrue()
        ->and(CouponHandle::isUlid((string) Str::ulid()))->toBeTrue()
        ->and(ProductHandle::looksLikeProductSlug('fc-26-coins-ps5'))->toBeTrue()
        ->and(ProductHandle::looksLikeProductSlug('FC-26-Coins'))->toBeFalse()
        ->and(ProductHandle::looksLikeProductSlug('fc_26'))->toBeFalse()
        ->and(CouponHandle::looksLikeCouponCode('SUMMER20'))->toBeTrue()
        ->and(CouponHandle::looksLikeCouponCode('summer20'))->toBeTrue()
        ->and(CouponHandle::looksLikeCouponCode((string) Str::ulid()))->toBeFalse()
        ->and(CouponHandle::looksLikeCouponCode('BAD CODE'))->toBeFalse();
});

test('a legacy product ULID keeps its query string through the slug redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $product = Product::factory()->create();

    $this->actingAs($admin)
        ->get("/admin/products/{$product->public_id}?tab=variants")
        ->assertStatus(301)
        ->assertRedirect("/admin/products/{$product->slug}?tab=variants");
});

test('a lowercase legacy product ULID is normalized before the redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $product = Product::factory()->create();

    $this->actingAs($admin)
        ->get('/admin/products/'.mb_strtolower((string) $product->public_id))
        ->assertStatus(301)
        ->assertRedirect("/admin/products/{$product->slug}");
});

test('the product detail resolves by slug in both route families', function (string $prefix): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $product = Product::factory()->create(['slug' => 'fut-champions-coins']);

    $this->actingAs($admin)
        ->get("{$prefix}/products/{$product->slug}")
        ->assertOk();
})->with(['/admin', '/en/admin']);

test('unknown product handles return 404 without a redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/products/no-such-product')
        ->assertNotFound();

    $this->actingAs($admin)
        ->get('/admin/products/'.(string) Str::ulid())
        ->assertNotFound();
});

test('product write endpoints resolve slug and ULID handles without a redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    foreach (['update-by-slug', 'update-by-ulid'] as $index => $slug) {
        $product = Product::factory()->create([
            'authority' => ProductAuthority::Manual,
            'slug' => $slug,
        ]);
        $handle = $index === 0 ? $product->slug : (string) $product->public_id;

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/admin/api/products/{$handle}", [
                'name_ar' => 'اسم محدث',
                'name_en' => 'Updated Name',
                'description_ar' => null,
                'description_en' => null,
                'is_visible' => true,
                'sort_order' => 7,
                'expected' => [
                    'name_ar' => (string) $product->name_ar,
                    'name_en' => (string) $product->name_en,
                    'description_ar' => $product->description_ar,
                    'description_en' => $product->description_en,
                    'is_visible' => (bool) $product->is_visible,
                    'sort_order' => (int) $product->sort_order,
                ],
            ])
            ->assertOk();

        expect($product->fresh()->sort_order)->toBe(7);
    }
});

test('product visibility resolves slug and ULID handles without a redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    $bySlug = Product::factory()->create(['slug' => 'hide-by-slug']);
    $byUlid = Product::factory()->create(['slug' => 'hide-by-ulid']);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/products/{$bySlug->slug}/visibility", [
            'hidden' => true,
            'expected_hidden' => false,
        ])
        ->assertOk();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/products/{$byUlid->public_id}/visibility", [
            'hidden' => true,
            'expected_hidden' => false,
        ])
        ->assertOk();

    expect($bySlug->fresh()->admin_hidden_at)->not->toBeNull()
        ->and($byUlid->fresh()->admin_hidden_at)->not->toBeNull();
});

test('a ULID-shaped product slug resolves through the fallback without self-redirecting', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    // A storefront slug that is itself a valid Crockford string: the public_id
    // lookup misses and the slug lookup must catch it, and the canonicalizing
    // redirect must not fire back at the same segment.
    $slug = mb_strtolower((string) Str::ulid());
    $product = Product::factory()->create(['slug' => $slug]);

    $this->actingAs($admin)
        ->get("/admin/products/{$slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.id', (string) $product->public_id));
});

test('the coupon detail resolves by code, lowercases the code, and redirects the ULID', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $coupon = Coupon::query()->create(catalogHandleCoupon(['code' => 'SUMMER20']));

    $this->actingAs($admin)
        ->get('/admin/marketing/coupons/SUMMER20')
        ->assertOk();

    $this->actingAs($admin)
        ->get('/admin/marketing/coupons/summer20')
        ->assertOk();

    $this->actingAs($admin)
        ->get("/admin/marketing/coupons/{$coupon->public_id}?tab=rules")
        ->assertStatus(301)
        ->assertRedirect('/admin/marketing/coupons/SUMMER20?tab=rules');
});

test('unknown coupon handles return 404 from the detail', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/marketing/coupons/NOSUCHCODE')
        ->assertNotFound();

    $this->actingAs($admin)
        ->get('/admin/marketing/coupons/'.(string) Str::ulid())
        ->assertNotFound();
});

test('every coupon write endpoint resolves code and ULID handles without a redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    foreach ([0, 1] as $index) {
        $coupon = Coupon::query()->create(catalogHandleCoupon(['code' => "WRITE{$index}CODE"]));
        $handle = $index === 0 ? $coupon->code : (string) $coupon->public_id;

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->putJson("/admin/api/marketing/coupons/{$handle}", catalogHandleCouponPayload([
                'code' => $coupon->code,
            ]))
            ->assertOk();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/admin/api/marketing/coupons/{$handle}/status", ['is_active' => false])
            ->assertOk();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/admin/api/marketing/coupons/{$handle}/duplicate", [
                'code' => "CLONE{$index}".Str::upper(Str::random(4)),
            ])
            ->assertCreated();
    }
});

test('renaming a coupon code returns the new url and retires the old one', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $coupon = Coupon::query()->create(catalogHandleCoupon(['code' => 'OLDCODE10']));

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->putJson("/admin/api/marketing/coupons/{$coupon->code}", catalogHandleCouponPayload([
            'code' => 'NEWCODE10',
        ]));

    $response->assertOk()
        ->assertJson([
            'data' => [
                'code' => 'NEWCODE10',
                'url' => '/admin/marketing/coupons/NEWCODE10',
            ],
        ]);

    $this->actingAs($admin)
        ->get('/admin/marketing/coupons/NEWCODE10')
        ->assertOk();

    $this->actingAs($admin)
        ->get('/admin/marketing/coupons/OLDCODE10')
        ->assertNotFound();
});

test('admin catalog surfaces never emit an internal ULID href', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    $product = Product::factory()->create(['slug' => 'guard-product']);
    $coupon = Coupon::query()->create(catalogHandleCoupon(['code' => 'GUARD10']));

    $responses = [
        $this->actingAs($admin)->get('/admin/products')->assertOk(),
        $this->actingAs($admin)->get("/admin/products/{$product->slug}")->assertOk(),
        $this->actingAs($admin)->get('/admin/marketing/coupons')->assertOk(),
        $this->actingAs($admin)->get("/admin/marketing/coupons/{$coupon->code}")->assertOk(),
    ];

    foreach ($responses as $response) {
        assertNoInternalCatalogUlidHrefs(adminCatalogHandleProps($response));
    }
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function catalogHandleCoupon(array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::ulid(),
        'code' => 'HANDLECODE',
        'discount_type' => 'percent',
        'value' => 20,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function catalogHandleCouponPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'HANDLEPAYLOAD',
        'discount_type' => 'percent',
        'value' => 15,
        'minimum_order_halalah' => 0,
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function adminCatalogHandleProps(TestResponse $response): array
{
    /** @var array{props?: array<string, mixed>} $page */
    $page = $response->viewData('page');

    return $page['props'] ?? [];
}

function assertNoInternalCatalogUlidHrefs(mixed $value): void
{
    if (is_string($value)) {
        expect($value)->not->toMatch('#/(products|marketing/coupons)/[0-9A-HJKMNP-TV-Z]{26}#i');

        return;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            assertNoInternalCatalogUlidHrefs($item);
        }
    }
}
