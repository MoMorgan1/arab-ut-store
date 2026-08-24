<?php

namespace Tests\Feature\Admin;

use App\Admin\Actions\UpdateAdminProduct;
use App\Enums\ProductAuthority;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests and nonprivileged accounts cannot update product details', function (): void {
    $product = createTestProduct();

    $this->postJson("/admin/api/products/{$product->public_id}", [
        'name_ar' => 'اسم جديد',
        'name_en' => 'New Name',
        'description_ar' => null,
        'description_en' => null,
        'is_visible' => true,
        'sort_order' => 1,
        'expected' => currentProductExpected($product),
    ])->assertUnauthorized();

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)
            ->postJson("/admin/api/products/{$product->public_id}", [
                'name_ar' => 'اسم جديد',
                'name_en' => 'New Name',
                'description_ar' => null,
                'description_en' => null,
                'is_visible' => true,
                'sort_order' => 1,
                'expected' => currentProductExpected($product),
            ])
            ->assertForbidden();
    }
});

test('staff actors and inactive admin actors are forbidden from updating products', function (): void {
    $staff = createProductTestAdmin(UserRole::Staff);
    $product = createTestProduct();

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/products/{$product->public_id}", [
            'name_ar' => 'اسم جديد',
            'name_en' => 'New Name',
            'description_ar' => null,
            'description_en' => null,
            'is_visible' => true,
            'sort_order' => 1,
            'expected' => currentProductExpected($product),
        ])
        ->assertForbidden();

    $inactiveAdmin = createProductTestAdmin(UserRole::Admin);
    $inactiveAdmin->forceFill(['is_active' => false])->save();

    $this->actingAs($inactiveAdmin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/products/{$product->public_id}", [
            'name_ar' => 'اسم جديد',
            'name_en' => 'New Name',
            'description_ar' => null,
            'description_en' => null,
            'is_visible' => true,
            'sort_order' => 1,
            'expected' => currentProductExpected($product),
        ])
        ->assertForbidden();
});

test('confirmed Admin can update manual product editable fields and records staff audit log', function (): void {
    $admin = createProductTestAdmin(UserRole::Admin);
    $product = createTestProduct();

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/products/{$product->public_id}", [
            'name_ar' => 'كوينز فيفا 26 محدث',
            'name_en' => 'FC 26 Coins Updated',
            'description_ar' => 'وصف عربي محدث',
            'description_en' => 'Updated English description',
            'is_visible' => false,
            'sort_order' => 99,
            'expected' => currentProductExpected($product),
        ]);

    $response->assertOk()
        ->assertJson([
            'data' => [
                'nameAr' => 'كوينز فيفا 26 محدث',
                'nameEn' => 'FC 26 Coins Updated',
                'descriptionAr' => 'وصف عربي محدث',
                'descriptionEn' => 'Updated English description',
                'isVisible' => false,
                'sortOrder' => 99,
            ],
        ]);

    $freshProduct = $product->fresh();
    expect($freshProduct->name_en)->toBe('FC 26 Coins Updated')
        ->and($freshProduct->name_ar)->toBe('كوينز فيفا 26 محدث')
        ->and($freshProduct->description_en)->toBe('Updated English description')
        ->and($freshProduct->description_ar)->toBe('وصف عربي محدث')
        ->and($freshProduct->is_visible)->toBeFalse()
        ->and($freshProduct->sort_order)->toBe(99);

    // Assert audit log exists
    $auditLog = StaffAuditLog::query()
        ->where('auditable_type', $product->getMorphClass())
        ->where('auditable_id', $product->getKey())
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->action)->toBe('products.updated')
        ->and($auditLog->actor_user_id)->toBe($admin->id)
        ->and($auditLog->metadata['product_changed'])->toContain('name_en', 'name_ar', 'description_en', 'description_ar', 'is_visible', 'sort_order');
});

test('updating an automation product is refused with 422 product_not_editable', function (): void {
    $admin = createProductTestAdmin(UserRole::Admin);
    $product = Product::factory()->create([
        'authority' => ProductAuthority::Automation,
    ]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/products/{$product->public_id}", [
            'name_ar' => 'اسم معدل',
            'name_en' => 'Modified Name',
            'description_ar' => null,
            'description_en' => null,
            'is_visible' => true,
            'sort_order' => 1,
            'expected' => currentProductExpected($product),
        ])
        ->assertStatus(422)
        ->assertJson([
            'reason' => 'product_not_editable',
            'product' => (string) $product->public_id,
        ]);
});

test('stale expected values cause 409 conflict with current product values', function (): void {
    $admin = createProductTestAdmin(UserRole::Admin);
    $product = createTestProduct();

    $staleExpected = currentProductExpected($product);
    $staleExpected['name_en'] = 'Stale Name In Cache';

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/products/{$product->public_id}", [
            'name_ar' => 'اسم معدل',
            'name_en' => 'Modified Name',
            'description_ar' => null,
            'description_en' => null,
            'is_visible' => true,
            'sort_order' => 1,
            'expected' => $staleExpected,
        ])
        ->assertStatus(409)
        ->assertJson([
            'product' => (string) $product->public_id,
            'current' => [
                'nameAr' => $product->name_ar,
                'nameEn' => $product->name_en,
                'descriptionAr' => $product->description_ar,
                'descriptionEn' => $product->description_en,
                'isVisible' => $product->is_visible,
                'sortOrder' => $product->sort_order,
            ],
        ]);
});

test('unexpected fields in request payload are rejected by validation', function (): void {
    $admin = createProductTestAdmin(UserRole::Admin);
    $product = createTestProduct();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/products/{$product->public_id}", [
            'name_ar' => 'اسم معدل',
            'name_en' => 'Modified Name',
            'description_ar' => null,
            'description_en' => null,
            'is_visible' => true,
            'sort_order' => 1,
            'price_halalah' => 100, // forbidden out of scope field
            'expected' => currentProductExpected($product),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['unexpected_fields']);
});

test('UpdateAdminProduct action directly refuses non-admin or inactive actors', function (): void {
    $staff = createProductTestAdmin(UserRole::Staff);
    $product = createTestProduct();
    $action = app(UpdateAdminProduct::class);

    expect(fn () => $action->execute(
        actor: $staff,
        productPublicId: (string) $product->public_id,
        nameAr: 'اسم',
        nameEn: 'Name',
        descriptionAr: null,
        descriptionEn: null,
        isVisible: true,
        sortOrder: 1,
        expected: currentProductExpected($product),
    ))->toThrow(AuthorizationException::class);
});

function createTestProduct(): Product
{
    return Product::factory()->create([
        'authority' => ProductAuthority::Manual,
        'name_ar' => 'كوينز فيفا 26',
        'name_en' => 'FC 26 Coins',
        'description_ar' => 'الوصف الأصلي',
        'description_en' => 'Original description',
        'is_visible' => true,
        'sort_order' => 10,
    ]);
}

/**
 * @return array{
 *     name_ar: string,
 *     name_en: string,
 *     description_ar: string|null,
 *     description_en: string|null,
 *     is_visible: bool,
 *     sort_order: int
 * }
 */
function currentProductExpected(Product $product): array
{
    return [
        'name_ar' => (string) $product->name_ar,
        'name_en' => (string) $product->name_en,
        'description_ar' => $product->description_ar !== null ? (string) $product->description_ar : null,
        'description_en' => $product->description_en !== null ? (string) $product->description_en : null,
        'is_visible' => (bool) $product->is_visible,
        'sort_order' => (int) $product->sort_order,
    ];
}

function createProductTestAdmin(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINUPDATEPRODUCTSTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
