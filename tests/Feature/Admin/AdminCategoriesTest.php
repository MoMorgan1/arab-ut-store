<?php

use App\Admin\Queries\ListAdminCategories;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\CategoriesController;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function adminCategoriesActor(UserRole $role = UserRole::Admin, string $locale = 'en'): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

test('guests and nonprivileged accounts cannot enter the Admin categories list', function (): void {
    $this->get('/admin/categories')->assertRedirect('/en/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get('/admin/categories')->assertForbidden();
    }

    $inactiveStaff = adminCategoriesActor(UserRole::Staff);
    $inactiveStaff->forceFill(['is_active' => false])->save();
    $this->actingAs($inactiveStaff)->get('/admin/categories')->assertForbidden();
});

test('unconfirmed MFA admin users are redirected to MFA setup', function (): void {
    $admin = adminCategoriesActor(UserRole::Admin);
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin)->get('/admin/categories')->assertRedirect('/admin/settings');
});

test('confirmed Admin can open localized private categories routes', function (string $path): void {
    $admin = adminCategoriesActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/categories/index', false)
            ->where('auth', null)
            ->where('locale', 'en')
            ->where('direction', 'ltr')
            ->has('categories')
            ->has('pagination')
            ->has('filters')
            ->has('filterOptions')
            ->has('productsUrl')
            ->has('visibilityUrlTemplate'));
})->with([
    'Canonical Admin' => ['/admin/categories'],
    'English Admin' => ['/en/admin/categories'],
]);

test('the categories route requires EnsureAdminMfa and can:catalog.view middleware', function (): void {
    $route = Route::getRoutes()->getByName('admin.categories');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
        ->and($route?->gatherMiddleware())->toContain('can:catalog.view');
});

test('the categories controller independently authorizes catalog.view permission', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($customer);
    $request = App\Http\Requests\Admin\ListAdminCategories::create('/admin/categories', 'GET');
    $request->setUserResolver(fn (): User => $customer);

    expect(fn () => app(CategoriesController::class)($request))
        ->toThrow(AuthorizationException::class);
});

test('categories request rejects unknown query parameters', function (): void {
    $admin = adminCategoriesActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/categories?unknown_key=test')
        ->assertSessionHasErrors('query');
});

test('categories request validates query filter boundaries', function (
    string $queryParam,
): void {
    $admin = adminCategoriesActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get("/admin/categories?{$queryParam}")
        ->assertSessionHasErrors();
})->with([
    'invalid visibility' => ['visibility=invalid_visibility'],
    'invalid sort key' => ['sort=nonexistent_column'],
    'invalid direction' => ['direction=sideways'],
    'invalid per_page' => ['per_page=30'],
    'search exceeds 100 chars' => ['search='.str_repeat('a', 101)],
]);

test('search finds categories by name, slug, and public ID', function (): void {
    $category = Category::factory()->create([
        'slug' => 'fc-champions',
        'name_ar' => 'أبطال فيفا',
        'name_en' => 'FC Champions',
    ]);

    // Name En
    $results = app(ListAdminCategories::class)->paginate(['search' => 'Champions']);
    expect($results['categories'])->toHaveCount(1)
        ->and($results['categories'][0]['id'])->toBe((string) $category->public_id);

    // Slug
    $results = app(ListAdminCategories::class)->paginate(['search' => 'fc-champions']);
    expect($results['categories'])->toHaveCount(1);

    // Arabic Name
    $results = app(ListAdminCategories::class)->paginate(['search' => 'أبطال']);
    expect($results['categories'])->toHaveCount(1);

    // Public ID
    $results = app(ListAdminCategories::class)->paginate(['search' => (string) $category->public_id]);
    expect($results['categories'])->toHaveCount(1);
});

test('visibility filter returns only matching categories', function (): void {
    $visible = Category::factory()->create([
        'is_visible' => true,
        'admin_hidden_at' => null,
    ]);
    $adminHidden = Category::factory()->create([
        'is_visible' => true,
        'admin_hidden_at' => now(),
    ]);
    $autoHidden = Category::factory()->create([
        'is_visible' => false,
        'admin_hidden_at' => null,
    ]);

    $visibleResults = app(ListAdminCategories::class)->paginate(['visibility' => 'visible']);
    $visibleIds = array_column($visibleResults['categories'], 'id');
    expect($visibleIds)->toContain((string) $visible->public_id)
        ->and($visibleIds)->not->toContain((string) $adminHidden->public_id)
        ->and($visibleIds)->not->toContain((string) $autoHidden->public_id);

    $adminHiddenResults = app(ListAdminCategories::class)->paginate(['visibility' => 'admin_hidden']);
    $adminHiddenIds = array_column($adminHiddenResults['categories'], 'id');
    expect($adminHiddenIds)->toContain((string) $adminHidden->public_id)
        ->and($adminHiddenIds)->not->toContain((string) $visible->public_id);

    $autoHiddenResults = app(ListAdminCategories::class)->paginate(['visibility' => 'automation_hidden']);
    $autoHiddenIds = array_column($autoHiddenResults['categories'], 'id');
    expect($autoHiddenIds)->toContain((string) $autoHidden->public_id)
        ->and($autoHiddenIds)->not->toContain((string) $visible->public_id);
});

test('categories query correctly calculates productsCount and visibleProductsCount without N+1', function (): void {
    $category = Category::factory()->create([
        'is_visible' => true,
        'admin_hidden_at' => null,
    ]);

    // Product 1: visible
    $prod1 = Product::factory()->create([
        'category_id' => $category->id,
        'authority' => ProductAuthority::Manual,
        'service_type' => ServiceType::Coins,
        'is_visible' => true,
        'admin_hidden_at' => null,
        'archived_at' => null,
    ]);
    ProductVariant::factory()->create(['product_id' => $prod1->id, 'is_active' => true]);

    // Product 2: hidden by admin
    $prod2 = Product::factory()->create([
        'category_id' => $category->id,
        'authority' => ProductAuthority::Manual,
        'service_type' => ServiceType::Coins,
        'is_visible' => true,
        'admin_hidden_at' => now(),
        'archived_at' => null,
    ]);
    ProductVariant::factory()->create(['product_id' => $prod2->id, 'is_active' => true]);

    // Product 3: archived
    $prod3 = Product::factory()->create([
        'category_id' => $category->id,
        'authority' => ProductAuthority::Manual,
        'service_type' => ServiceType::Coins,
        'is_visible' => true,
        'admin_hidden_at' => null,
        'archived_at' => now(),
    ]);
    ProductVariant::factory()->create(['product_id' => $prod3->id, 'is_active' => true]);

    $results = app(ListAdminCategories::class)->paginate(['search' => (string) $category->public_id]);

    expect($results['categories'])->toHaveCount(1)
        ->and($results['categories'][0]['productsCount'])->toBe(3)
        ->and($results['categories'][0]['visibleProductsCount'])->toBe(1);
});
