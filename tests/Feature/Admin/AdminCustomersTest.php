<?php

use App\Admin\Queries\ListAdminCustomers;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\CustomersController;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('the Admin customer list index migration rolls back and reapplies on disposable SQLite', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'arab-ut-admin-customers-');

    if ($databasePath === false) {
        throw new RuntimeException('Unable to create the disposable Admin customers SQLite database.');
    }

    config(['database.connections.admin_customers_lifecycle' => [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);
    DB::purge('admin_customers_lifecycle');
    DB::setDefaultConnection('admin_customers_lifecycle');

    try {
        createAdminCustomersLifecycleTables();
        $migration = require database_path('migrations/2026_08_22_000003_add_admin_customer_list_indexes.php');

        $migration->up();
        assertAdminCustomersLifecycleIndexes(true);

        $migration->down();
        assertAdminCustomersLifecycleIndexes(false);

        $migration->up();
        assertAdminCustomersLifecycleIndexes(true);
    } finally {
        DB::disconnect('admin_customers_lifecycle');
        DB::purge('admin_customers_lifecycle');
        DB::setDefaultConnection($originalConnection);
        unlink($databasePath);
    }
});

test('guests and nonprivileged accounts cannot enter the Admin customers list', function (): void {
    $this->get('/admin/customers')->assertRedirect('/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get('/admin/customers')->assertForbidden();
    }

    $inactiveStaff = adminCustomersActor(UserRole::Staff);
    $inactiveStaff->forceFill(['is_active' => false])->save();
    $this->actingAs($inactiveStaff)->get('/admin/customers')->assertForbidden();
});

test('staff users are forbidden from the customers list', function (): void {
    $staff = adminCustomersActor(UserRole::Staff);

    $this->actingAs($staff)->get('/admin/customers')->assertForbidden();
});

test('unconfirmed MFA admin users are redirected to MFA setup', function (): void {
    $admin = adminCustomersActor(UserRole::Admin);
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin)->get('/admin/customers')->assertRedirect('/admin/settings');
});

test('confirmed Admin can open localized private customers routes', function (string $path): void {
    $admin = adminCustomersActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/customers/index', false)
            ->where('auth', null)
            ->where('locale', str_starts_with($path, '/en/') ? 'en' : 'ar')
            ->where('direction', str_starts_with($path, '/en/') ? 'ltr' : 'rtl')
            ->has('customers')
            ->has('pagination')
            ->has('filters')
            ->has('filterOptions'));
})->with([
    'Canonical Admin' => ['/admin/customers'],
    'English Admin' => ['/en/admin/customers'],
]);

test('admin navigation URLs include customers between orders and settings', function (string $path, array $expectedUrls): void {
    $actor = adminCustomersActor(UserRole::Admin);

    $this->actingAs($actor)
        ->get($path)
        ->assertOk()
        // Compare the whole list, not a fixed number of indexes: an
        // enumeration silently stops checking whatever it does not reach, so
        // a nav entry added at the end went unasserted.
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where(
                'adminNavigation',
                fn (Collection $navigation): bool => $navigation
                    ->pluck('url')
                    ->all() === $expectedUrls,
            ));
})->with([
    'Canonical family' => ['/admin/customers', ['/admin', '/admin/orders', '/admin/customers', '/admin/conversations', '/admin/products', '/admin/marketing/coupons', '/admin/settings', '/admin/more']],
    'Localized family' => ['/en/admin/customers', ['/en/admin', '/en/admin/orders', '/en/admin/customers', '/en/admin/conversations', '/en/admin/products', '/en/admin/marketing/coupons', '/en/admin/settings', '/en/admin/more']],
]);

test('the customers route requires EnsureAdminMfa and can:customers.view middleware', function (): void {
    $route = Route::getRoutes()->getByName('admin.customers');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
        ->and($route?->gatherMiddleware())->toContain('can:customers.view');
});

test('the customers controller independently authorizes customers.view permission', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($customer);
    $request = App\Http\Requests\Admin\ListAdminCustomers::create('/admin/customers', 'GET');
    $request->setUserResolver(fn (): User => $customer);

    expect(fn () => app(CustomersController::class)($request))
        ->toThrow(AuthorizationException::class);
});

test('customers request rejects unknown query parameters', function (): void {
    $admin = adminCustomersActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/customers?unknown_key=test')
        ->assertSessionHasErrors('query');
});

test('customers request validates all query filter boundaries', function (
    string $queryParam,
): void {
    $admin = adminCustomersActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get("/admin/customers?{$queryParam}")
        ->assertSessionHasErrors();
})->with([
    'invalid status' => ['status=invalid_status'],
    'invalid date_from format' => ['date_from=20-08-2026'],
    'invalid date_to format' => ['date_to=not-a-date'],
    'date_to before date_from' => ['date_from=2026-08-20&date_to=2026-08-10'],
    'invalid sort key' => ['sort=nonexistent_column'],
    'invalid direction' => ['direction=sideways'],
    'invalid per_page' => ['per_page=30'],
    'search exceeds 100 chars' => ['search='.str_repeat('a', 101)],
]);

test('search finds a customer by the short number staff read aloud', function (): void {
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'Nawaf',
        'last_name' => 'Al-Dossari',
    ]);

    $number = (string) $customer->customer_number;

    foreach ([$number, strtolower($number), substr($number, 4)] as $term) {
        $results = app(ListAdminCustomers::class)->paginate(['search' => $term]);

        expect($results['customers'])->toHaveCount(1)
            ->and($results['customers'][0]['id'])->toBe((string) $customer->public_id)
            ->and($results['customers'][0]['number'])->toBe($number);
    }
});

test('search finds customers by name, email, phone digits, and public ID', function (): void {
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'Tariq',
        'last_name' => 'Al-Harbi',
        'email' => 'tariq.harbi@example.test',
        'phone' => '+966551234567',
    ]);

    // Exact email
    $results = app(ListAdminCustomers::class)->paginate(['search' => 'tariq.harbi@example.test']);
    expect($results['customers'])->toHaveCount(1)
        ->and($results['customers'][0]['id'])->toBe((string) $customer->public_id);

    // Case-insensitive email
    $results = app(ListAdminCustomers::class)->paginate(['search' => 'TARIQ.HARBI@EXAMPLE.TEST']);
    expect($results['customers'])->toHaveCount(1);

    // First name
    $results = app(ListAdminCustomers::class)->paginate(['search' => 'Tariq']);
    expect($results['customers'])->toHaveCount(1);

    // Last name
    $results = app(ListAdminCustomers::class)->paginate(['search' => 'Al-Harbi']);
    expect($results['customers'])->toHaveCount(1);

    // Exact phone
    $results = app(ListAdminCustomers::class)->paginate(['search' => '+966551234567']);
    expect($results['customers'])->toHaveCount(1);

    // Stripped phone digits
    $results = app(ListAdminCustomers::class)->paginate(['search' => '966551234567']);
    expect($results['customers'])->toHaveCount(1);

    // Public ULID
    $results = app(ListAdminCustomers::class)->paginate(['search' => (string) $customer->public_id]);
    expect($results['customers'])->toHaveCount(1);
});

test('status filter returns only active or suspended customers', function (): void {
    $active = User::factory()->create([
        'role' => UserRole::Customer,
        'is_active' => true,
    ]);
    $suspended = User::factory()->create([
        'role' => UserRole::Customer,
        'is_active' => false,
    ]);

    $activeResults = app(ListAdminCustomers::class)->paginate(['status' => 'active']);
    $activeIds = array_column($activeResults['customers'], 'id');
    expect($activeIds)->toContain((string) $active->public_id)
        ->and($activeIds)->not->toContain((string) $suspended->public_id);

    $suspendedResults = app(ListAdminCustomers::class)->paginate(['status' => 'suspended']);
    $suspendedIds = array_column($suspendedResults['customers'], 'id');
    expect($suspendedIds)->toContain((string) $suspended->public_id)
        ->and($suspendedIds)->not->toContain((string) $active->public_id);
});

test('date filters filter customers by created_at boundary', function (): void {
    Carbon::setTestNow('2026-08-20 12:00:00');
    $oldCustomer = User::factory()->create([
        'role' => UserRole::Customer,
        'created_at' => '2026-08-01 10:00:00',
    ]);
    $targetCustomer = User::factory()->create([
        'role' => UserRole::Customer,
        'created_at' => '2026-08-15 10:00:00',
    ]);
    $futureCustomer = User::factory()->create([
        'role' => UserRole::Customer,
        'created_at' => '2026-08-20 10:00:00',
    ]);

    $results = app(ListAdminCustomers::class)->paginate([
        'date_from' => '2026-08-10',
        'date_to' => '2026-08-16',
    ]);

    $ids = array_column($results['customers'], 'id');
    expect($ids)->toContain((string) $targetCustomer->public_id)
        ->and($ids)->not->toContain((string) $oldCustomer->public_id)
        ->and($ids)->not->toContain((string) $futureCustomer->public_id);
});

test('sorting correctly sorts by total_spent orders_count and name', function (): void {
    $custA = User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'Abdullah',
    ]);
    $custB = User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'Zayd',
    ]);

    // Give CustA 1 completed order with 5000 halalah
    Order::factory()->for($custA)->create([
        'status' => OrderStatus::Completed,
        'total_halalah' => 5000,
        'placed_at' => now(),
    ]);

    // Give CustB 2 completed orders with 20000 total halalah
    Order::factory()->for($custB)->create([
        'status' => OrderStatus::Completed,
        'total_halalah' => 10000,
        'placed_at' => now(),
    ]);
    Order::factory()->for($custB)->create([
        'status' => OrderStatus::Completed,
        'total_halalah' => 10000,
        'placed_at' => now(),
    ]);

    // Sort by total_spent desc
    $results = app(ListAdminCustomers::class)->paginate([
        'sort' => 'total_spent',
        'direction' => 'desc',
    ]);
    expect($results['customers'][0]['id'])->toBe((string) $custB->public_id);

    // Sort by name asc
    $nameResults = app(ListAdminCustomers::class)->paginate([
        'sort' => 'name',
        'direction' => 'asc',
    ]);
    $names = array_column($nameResults['customers'], 'name');
    expect($names[0])->toContain('Abdullah');
});

test('total_spent excludes pending_payment cancelled and refunded orders', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    Order::factory()->for($customer)->create([
        'status' => OrderStatus::Completed,
        'total_halalah' => 3000,
        'placed_at' => now(),
    ]);
    Order::factory()->for($customer)->create([
        'status' => OrderStatus::PendingPayment,
        'total_halalah' => 9000,
        'placed_at' => now(),
    ]);
    Order::factory()->for($customer)->create([
        'status' => OrderStatus::Cancelled,
        'total_halalah' => 4000,
        'placed_at' => now(),
    ]);
    Order::factory()->for($customer)->create([
        'status' => OrderStatus::Refunded,
        'total_halalah' => 2000,
        'placed_at' => now(),
    ]);

    $results = app(ListAdminCustomers::class)->paginate(['search' => (string) $customer->public_id]);
    expect($results['customers'][0]['totalSpent']['amountMinor'])->toBe('3000');
});

test('customers response props never leak secrets or Admin/Staff accounts', function (): void {
    $admin = adminCustomersActor(UserRole::Admin);
    $staff = adminCustomersActor(UserRole::Staff);
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'password' => 'CustomerSecretPassword!123',
    ]);

    $response = $this->actingAs($admin)->get('/admin/customers');
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->not->toContain('CustomerSecretPassword!123')
        ->and($content)->not->toContain('two_factor_secret')
        ->and($content)->not->toContain('two_factor_recovery_codes')
        ->and($content)->not->toContain('remember_token');

    $props = $response->original->getData()['page']['props'];
    $customerIds = array_column($props['customers'], 'id');
    expect($customerIds)->toContain((string) $customer->public_id)
        ->and($customerIds)->not->toContain((string) $admin->public_id)
        ->and($customerIds)->not->toContain((string) $staff->public_id);
});

function createAdminCustomersLifecycleTables(): void
{
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('role')->default('customer');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id');
        $table->string('status');
    });
}

function assertAdminCustomersLifecycleIndexes(bool $expected): void
{
    expect(Schema::hasIndex('users', 'idx_users_admin_role_created_at'))->toBe($expected)
        ->and(Schema::hasIndex('users', 'idx_users_admin_role_is_active'))->toBe($expected)
        ->and(Schema::hasIndex('orders', 'idx_orders_admin_user_status'))->toBe($expected);
}

function adminCustomersActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINCUSTOMERSTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
