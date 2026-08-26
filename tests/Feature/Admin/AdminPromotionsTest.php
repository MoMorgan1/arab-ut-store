<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', 'UTC'));
});

test('guests and nonprivileged accounts cannot enter the marketing promotions page', function (): void {
    $this->get('/admin/marketing/promotions')->assertRedirect('/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get('/admin/marketing/promotions')->assertForbidden();
    }
});

test('staff users are forbidden from the marketing promotions page', function (): void {
    $staff = adminPromotionsActor(UserRole::Staff);

    $this->actingAs($staff)->get('/admin/marketing/promotions')->assertForbidden();
});

test('confirmed admin can open localized promotion pages with counts and categories', function (string $path, string $direction): void {
    $admin = adminPromotionsActor(UserRole::Admin);
    Category::factory()->create(['name_en' => 'Icon challenges']);
    Promotion::query()->create(promotionAttributes([
        'name_en' => 'Summer deal',
        'name_ar' => 'عرض الصيف',
        'is_active' => true,
        'created_at' => Carbon::parse('2026-08-20 12:00:00', 'UTC'),
    ]));
    Promotion::query()->create(promotionAttributes([
        'name_en' => 'Ended deal',
        'name_ar' => 'عرض منتهي',
        'is_active' => false,
    ]));

    $this->actingAs($admin)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/marketing/promotions', false)
            ->where('auth', null)
            ->where('direction', $direction)
            ->has('promotions', 2)
            ->has('pagination')
            ->where('counts.total', 2)
            ->where('counts.active', 1)
            ->where('promotions.0.nameEn', 'Ended deal')
            ->has('categories', 1)
            ->where('categories.0.name', 'Icon challenges'));
})->with([
    'Canonical' => ['/admin/marketing/promotions', 'rtl'],
    'Localized' => ['/en/admin/marketing/promotions', 'ltr'],
]);

test('the promotions route requires EnsureAdminMfa and can:marketing.view middleware', function (): void {
    $route = Route::getRoutes()->getByName('admin.marketing.promotions');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
        ->and($route?->gatherMiddleware())->toContain('can:marketing.view');
});

test('confirmed admin can create a scoped promotion with an audit log entry', function (): void {
    $admin = adminPromotionsActor(UserRole::Admin);
    $category = Category::factory()->create();

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/promotions', [
            'name_ar' => 'عرض الفئة',
            'name_en' => 'Category deal',
            'badge_ar' => 'خصم 20%',
            'badge_en' => '20% off',
            'scope' => 'category',
            'category' => $category->public_id,
            'discount_type' => 'percent',
            'value' => 20,
            'starts_at' => '2026-08-01T00:00:00+00:00',
            'ends_at' => '2026-09-01T00:00:00+00:00',
            'is_active' => true,
        ]);

    $response->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['data' => ['nameEn' => 'Category deal', 'isActive' => true]]);

    $promotion = Promotion::query()->first();

    expect($promotion?->scope)->toBe('category')
        ->and($promotion?->category_id)->toBe($category->id)
        ->and($promotion?->service_type)->toBeNull()
        ->and($promotion?->value)->toBe(20)
        ->and($promotion?->starts_at?->format('Y-m-d'))->toBe('2026-08-01');

    $log = StaffAuditLog::query()->latest('id')->first();

    expect($log?->action)->toBe('promotions.created')
        ->and($log?->actor_user_id)->toBe($admin->id)
        ->and($log?->metadata['scope'])->toBe('category');
});

test('confirmed admin can update a promotion while switching its scope cleanly', function (): void {
    $admin = adminPromotionsActor(UserRole::Admin);
    $previousCategory = Category::factory()->create();
    $promotion = Promotion::query()->create(promotionAttributes([
        'scope' => Promotion::SCOPE_CATEGORY,
        'category_id' => $previousCategory->id,
    ]));

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->putJson("/admin/api/marketing/promotions/{$promotion->public_id}", [
            'name_ar' => 'عرض الخدمة',
            'name_en' => 'Service deal',
            'scope' => 'service',
            'service_type' => 'rivals',
            'discount_type' => 'fixed',
            'value' => 1500,
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJson(['data' => ['id' => (string) $promotion->public_id, 'isActive' => false]]);

    $promotion->refresh();

    expect($promotion->scope)->toBe('service')
        ->and($promotion->service_type)->toBe('rivals')
        ->and($promotion->category_id)->toBeNull()
        ->and($promotion->value)->toBe(1500)
        ->and($promotion->is_active)->toBeFalse();

    $log = StaffAuditLog::query()->latest('id')->first();

    expect($log?->action)->toBe('promotions.updated');
});

test('staff actors are forbidden from promotion mutations even with confirmed passwords', function (): void {
    $staff = adminPromotionsActor(UserRole::Staff);

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/promotions', promotionPayload())
        ->assertForbidden();
});

test('confirmed admin can toggle promotion status with an audit log entry', function (): void {
    $admin = adminPromotionsActor(UserRole::Admin);
    $promotion = Promotion::query()->create(promotionAttributes(['is_active' => true]));

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/marketing/promotions/{$promotion->public_id}/status", ['is_active' => false])
        ->assertOk()
        ->assertJson(['data' => ['isActive' => false]]);

    expect($promotion->fresh()->is_active)->toBeFalse();

    $log = StaffAuditLog::query()->latest('id')->first();

    expect($log?->action)->toBe('promotions.deactivated')
        ->and($log?->metadata['new_active'])->toBeFalse();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/admin/api/marketing/promotions/{$promotion->public_id}/status", ['is_active' => true])
        ->assertOk();

    expect($promotion->fresh()->is_active)->toBeTrue();
});

test('promotion create requests validate every rule boundary', function (array $payload, string $field): void {
    $admin = adminPromotionsActor(UserRole::Admin);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/promotions', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'missing arabic name' => [promotionPayload(['name_ar' => null]), 'name_ar'],
    'missing english name' => [promotionPayload(['name_en' => '']), 'name_en'],
    'badge too long' => [promotionPayload(['badge_en' => str_repeat('x', 25)]), 'badge_en'],
    'unknown scope' => [promotionPayload(['scope' => 'platform']), 'scope'],
    'percent above 90' => [promotionPayload(['discount_type' => 'percent', 'value' => 91]), 'value'],
    'percent zero' => [promotionPayload(['discount_type' => 'percent', 'value' => 0]), 'value'],
    'fixed below one riyal' => [promotionPayload(['discount_type' => 'fixed', 'value' => 99]), 'value'],
    'negative value' => [promotionPayload(['value' => -5]), 'value'],
    'ends before starts' => [promotionPayload(['starts_at' => '2026-08-01T00:00:00+00:00', 'ends_at' => '2026-07-01T00:00:00+00:00']), 'ends_at'],
    'category scope without category' => [promotionPayload(['scope' => 'category']), 'category'],
    'category field outside category scope' => [promotionPayload(['category' => '01J0000000000000000000000']), 'category'],
    'service scope without service type' => [promotionPayload(['scope' => 'service']), 'service_type'],
    'service type outside service scope' => [promotionPayload(['service_type' => 'coins']), 'service_type'],
    'unknown service type' => [promotionPayload(['scope' => 'service', 'service_type' => 'trading']), 'service_type'],
    'unknown discount type' => [promotionPayload(['discount_type' => 'bogo']), 'discount_type'],
]);

test('editing a promotion does not silently strip its limits into an unlimited offer', function (): void {
    $admin = adminPromotionsActor(UserRole::Admin);

    $promotion = Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض محدود',
        'name_en' => 'Limited offer',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'buy_quantity' => 3,
        'get_quantity' => 1,
        'max_applications' => 1,
        'discount_target' => Promotion::TARGET_MOST_EXPENSIVE,
        'qualifying_scope' => Promotion::QUALIFYING_SCOPE_SAME_CATEGORY,
        'discount_type' => 'percent',
        'value' => 50,
        'is_active' => true,
    ]);

    // A partial update - fixing a typo in the name - must not reset the terms.
    // The engine reads a null buy/get as 1 and a null max_applications as
    // uncapped, so losing them turns "buy 3 get 1, max 1" into
    // "buy 1 get 1, unlimited": every second item free, across the whole cart.
    $this->actingAs($admin)
        ->putJson("/admin/api/marketing/promotions/{$promotion->public_id}", [
            'name_ar' => 'عرض محدود',
            'name_en' => 'Limited offer (fixed)',
            'scope' => Promotion::SCOPE_ALL,
            'discount_type' => 'percent',
            'value' => 50,
        ])
        ->assertOk();

    $fresh = $promotion->fresh();

    expect($fresh->name_en)->toBe('Limited offer (fixed)')
        ->and($fresh->buy_quantity)->toBe(3)
        ->and($fresh->get_quantity)->toBe(1)
        ->and($fresh->max_applications)->toBe(1)
        ->and($fresh->discount_target)->toBe(Promotion::TARGET_MOST_EXPENSIVE)
        ->and($fresh->qualifying_scope)->toBe(Promotion::QUALIFYING_SCOPE_SAME_CATEGORY);
});

test('confirmed admin can create an nth_item promotion', function (): void {
    $admin = adminPromotionsActor(UserRole::Admin);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/promotions', [
            'name_ar' => 'عرض اشتر واحصل',
            'name_en' => 'BOGO deal',
            'mechanic' => 'nth_item',
            'scope' => 'all',
            'discount_type' => 'percent',
            'value' => 50,
            'buy_quantity' => 2,
            'get_quantity' => 1,
            'max_applications' => 3,
            'discount_target' => 'cheapest',
            'qualifying_scope' => 'same_product',
            'applies_to_promoted_items' => true,
            'is_active' => true,
        ]);

    $response->assertCreated()
        ->assertJson(['data' => ['nameEn' => 'BOGO deal']]);

    $promotion = Promotion::query()->latest('id')->first();

    expect($promotion?->mechanic)->toBe('nth_item')
        ->and($promotion?->buy_quantity)->toBe(2)
        ->and($promotion?->get_quantity)->toBe(1)
        ->and($promotion?->max_applications)->toBe(3)
        ->and($promotion?->discount_target)->toBe('cheapest')
        ->and($promotion?->qualifying_scope)->toBe('same_product')
        ->and($promotion?->applies_to_promoted_items)->toBeTrue();
});

test('confirmed admin can create a bundle promotion with components', function (): void {
    $admin = adminPromotionsActor(UserRole::Admin);
    $product1 = Product::factory()->create();
    $product2 = Product::factory()->create();

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/promotions', [
            'name_ar' => 'باقة التوفير',
            'name_en' => 'Savings Bundle',
            'mechanic' => 'bundle',
            'scope' => 'all',
            'bundle_price_halalah' => 7500,
            'components' => [
                ['product_id' => $product1->public_id, 'quantity' => 2],
                ['product_id' => $product2->public_id, 'quantity' => 1],
            ],
            'is_active' => true,
        ]);

    $response->assertCreated()
        ->assertJson(['data' => ['nameEn' => 'Savings Bundle']]);

    $promotion = Promotion::query()->latest('id')->first();

    expect($promotion?->mechanic)->toBe('bundle')
        ->and($promotion?->bundle_price_halalah)->toBe(7500)
        ->and($promotion?->components)->toHaveCount(2);

    $comps = $promotion?->components()->orderBy('id')->get();
    expect($comps?->first()?->product_id)->toBe($product1->id)
        ->and($comps?->first()?->quantity)->toBe(2)
        ->and($comps?->last()?->product_id)->toBe($product2->id)
        ->and($comps?->last()?->quantity)->toBe(1);
});

test('nth_item and bundle requests validate boundary constraints', function (): void {
    $admin = adminPromotionsActor(UserRole::Admin);
    $product1 = Product::factory()->create();

    // 0 buy quantity rejected
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/promotions', [
            'name_ar' => 'عرض',
            'name_en' => 'Deal',
            'mechanic' => 'nth_item',
            'scope' => 'all',
            'discount_type' => 'percent',
            'value' => 50,
            'buy_quantity' => 0,
            'get_quantity' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('buy_quantity');

    // Bundle with < 2 components rejected
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/promotions', [
            'name_ar' => 'باقة',
            'name_en' => 'Bundle',
            'mechanic' => 'bundle',
            'scope' => 'all',
            'bundle_price_halalah' => 5000,
            'components' => [
                ['product_id' => $product1->public_id, 'quantity' => 1],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('components');

    // Bundle missing price rejected
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/promotions', [
            'name_ar' => 'باقة',
            'name_en' => 'Bundle',
            'mechanic' => 'bundle',
            'scope' => 'all',
            'components' => [
                ['product_id' => $product1->public_id, 'quantity' => 1],
                ['product_id' => $product1->public_id, 'quantity' => 2],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('bundle_price_halalah');
});

test('category scope rejects an unknown category public id', function (): void {
    $admin = adminPromotionsActor(UserRole::Admin);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/promotions', promotionPayload([
            'scope' => 'category',
            'category' => '01J0000000000000000000000',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('category');
});

test('updating or toggling an unknown public id fails', function (): void {
    $admin = adminPromotionsActor(UserRole::Admin);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->putJson('/admin/api/marketing/promotions/01J0000000000000000000000', promotionPayload())
        ->assertNotFound();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/admin/api/marketing/promotions/01J0000000000000000000000/status', ['is_active' => true])
        ->assertNotFound();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promotionAttributes(array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض تجريبي',
        'name_en' => 'Test deal',
        'badge_ar' => 'خصم 10%',
        'badge_en' => '10% off',
        'scope' => Promotion::SCOPE_ALL,
        'discount_type' => 'percent',
        'value' => 10,
        'is_active' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promotionPayload(array $overrides = []): array
{
    return array_merge([
        'name_ar' => 'عرض جديد',
        'name_en' => 'Fresh deal',
        'scope' => 'all',
        'discount_type' => 'percent',
        'value' => 15,
    ], $overrides);
}

function adminPromotionsActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINPROMOTOTPSecret'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
