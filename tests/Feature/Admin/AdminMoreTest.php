<?php

use App\Admin\Presenters\AdminMorePage;
use App\Admin\Presenters\AdminShell;
use App\Enums\UserRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

function adminMoreActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINMORETOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}

test('AdminShell emits the grouped navigation tree with catalog, marketing, and more', function (): void {
    $admin = adminMoreActor(UserRole::Admin, 'en');

    $shell = app(AdminShell::class)->for($admin, 'en');
    $keys = array_column($shell['adminNavigation'], 'key');

    expect($keys)->toBe([
        'overview',
        'orders',
        'customers',
        'conversations',
        'catalog',
        'marketing',
        'settings',
        'more',
    ]);

    // Catalog has products and categories as children
    $catalogEntry = collect($shell['adminNavigation'])->firstWhere('key', 'catalog');
    expect($catalogEntry)->not->toBeNull()
        ->and($catalogEntry['children'])->toBeArray()
        ->and(array_column($catalogEntry['children'], 'key'))->toBe(['products', 'categories'])
        ->and(array_column($catalogEntry['children'], 'url'))->toBe(['/admin/products', '/admin/categories']);

    // Marketing has coupons, promotions, and loyalty as children
    $marketingEntry = collect($shell['adminNavigation'])->firstWhere('key', 'marketing');
    expect($marketingEntry)->not->toBeNull()
        ->and($marketingEntry['children'])->toBeArray()
        ->and(array_column($marketingEntry['children'], 'key'))->toBe([
            'marketingCoupons',
            'marketingPromotions',
            'marketingLoyalty',
        ])
        ->and(array_column($marketingEntry['children'], 'url'))->toBe([
            '/admin/marketing/coupons',
            '/admin/marketing/promotions',
            '/admin/marketing/loyalty',
        ]);

    // Loyalty is NOT a top-level sibling
    expect($keys)->not->toContain('marketingLoyalty');
});

test('AdminShell filters grouped navigation based on individual permissions', function (): void {
    $staff = adminMoreActor(UserRole::Staff, 'en');

    $shell = app(AdminShell::class)->for($staff, 'en');
    $keys = array_column($shell['adminNavigation'], 'key');

    // Staff with only order permissions sees no catalog or marketing groups
    expect($keys)->toBe(['overview', 'orders', 'settings', 'more'])
        ->and($keys)->not->toContain('catalog')
        ->and($keys)->not->toContain('marketing');
});

test('Admin more page renders all six permission-filtered tiles for Admin actor', function (string $url, string $expectedLocale, string $expectedDirection): void {
    $admin = adminMoreActor(UserRole::Admin, $expectedLocale);

    $response = $this->actingAs($admin)->get($url);

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/more')
            ->where('locale', $expectedLocale)
            ->where('direction', $expectedDirection)
            ->has('groups', 3)
            ->where('groups.0.key', 'catalog')
            ->where('groups.0.tiles.0.key', 'categories')
            ->where('groups.1.key', 'marketing')
            ->has('groups.1.tiles', 3)
            ->where('groups.1.tiles.0.key', 'coupons')
            ->where('groups.1.tiles.1.key', 'promotions')
            ->where('groups.1.tiles.2.key', 'loyalty')
            ->where('groups.2.key', 'system')
            ->has('groups.2.tiles', 2)
            ->where('groups.2.tiles.0.key', 'conversations')
            ->where('groups.2.tiles.1.key', 'settings')
        );
})->with([
    // The canonical family is Arabic and the /en family is English
    // (routes/admin.php), so these rows now prove both the reachability and the
    // locale of each.
    'Canonical family' => ['/admin/more', 'ar', 'rtl'],
    'Localized family' => ['/en/admin/more', 'en', 'ltr'],
]);

test('Admin more page tile URLs are all real named routes', function (): void {
    $admin = adminMoreActor(UserRole::Admin, 'en');
    $page = app(AdminMorePage::class)->for($admin, 'en');

    $allTiles = collect($page['groups'])->flatMap(fn (array $group) => $group['tiles']);
    expect($allTiles)->toHaveCount(6);

    foreach ($allTiles as $tile) {
        expect($tile['url'])->toBeString()->not->toBeEmpty();
        expect($tile['label'])->toBeString()->not->toBeEmpty();
        expect($tile['description'])->toBeString()->not->toBeEmpty();
    }

    $urlMap = $allTiles->pluck('url', 'key')->all();
    expect($urlMap['categories'])->toBe('/admin/categories')
        ->and($urlMap['coupons'])->toBe('/admin/marketing/coupons')
        ->and($urlMap['promotions'])->toBe('/admin/marketing/promotions')
        ->and($urlMap['loyalty'])->toBe('/admin/marketing/loyalty')
        ->and($urlMap['conversations'])->toBe('/admin/conversations')
        ->and($urlMap['settings'])->toBe('/admin/settings');
});

test('Admin more page filters tiles for Staff actor with only order permissions', function (): void {
    $staff = adminMoreActor(UserRole::Staff, 'en');

    $response = $this->actingAs($staff)->get('/admin/more');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/more')
            ->has('groups', 0)
        );
});

test('Admin more page requires authentication and confirmed MFA', function (): void {
    $this->get('/admin/more')->assertRedirect('/login');

    $userWithoutMfa = User::factory()->create([
        'role' => UserRole::Admin,
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);

    $this->actingAs($userWithoutMfa)->get('/admin/more')->assertRedirect('/admin/settings');
});

test('Admin more page forbids customer role', function (): void {
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
    ]);

    $this->actingAs($customer)->get('/admin/more')->assertForbidden();
});
