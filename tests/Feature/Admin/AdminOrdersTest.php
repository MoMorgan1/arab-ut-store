<?php

use App\Admin\Presenters\AdminOrdersPage;
use App\Admin\Queries\ListAdminOrders;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\OrdersController;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('the Admin orders list index migration rolls back and reapplies on disposable SQLite', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'arab-ut-admin-orders-');

    if ($databasePath === false) {
        throw new RuntimeException('Unable to create the disposable Admin orders SQLite database.');
    }

    config(['database.connections.admin_orders_lifecycle' => [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);
    DB::purge('admin_orders_lifecycle');
    DB::setDefaultConnection('admin_orders_lifecycle');

    try {
        createAdminOrdersLifecycleTables();
        $migration = require database_path('migrations/2026_08_22_000001_add_admin_order_list_indexes.php');

        $migration->up();
        assertAdminOrdersLifecycleIndexes(true);

        $migration->down();
        assertAdminOrdersLifecycleIndexes(false);

        $migration->up();
        assertAdminOrdersLifecycleIndexes(true);
    } finally {
        DB::disconnect('admin_orders_lifecycle');
        DB::purge('admin_orders_lifecycle');
        DB::setDefaultConnection($originalConnection);
        unlink($databasePath);
    }
});

test('guests and nonprivileged accounts cannot enter the Admin orders list', function (): void {
    $this->get('/admin/orders')->assertRedirect('/en/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get('/admin/orders')->assertForbidden();
    }

    $inactiveStaff = adminOrdersActor(UserRole::Staff);
    $inactiveStaff->forceFill(['is_active' => false])->save();
    $this->actingAs($inactiveStaff)->get('/admin/orders')->assertForbidden();
});

test('unconfirmed MFA privileged users are redirected to MFA setup', function (): void {
    $admin = adminOrdersActor(UserRole::Admin);
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin)->get('/admin/orders')->assertRedirect('/admin/security/mfa');
});

test('confirmed privileged actors can open localized private orders routes', function (
    UserRole $role,
    string $path,
): void {
    $actor = adminOrdersActor($role);

    $this->actingAs($actor)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/orders/index', false)
            ->where('auth', null)
            ->where('locale', 'en')
            ->where('direction', 'ltr')
            ->has('orders')
            ->has('pagination')
            ->has('filters')
            ->has('filterOptions'));
})->with([
    'Canonical Admin' => [UserRole::Admin, '/admin/orders'],
    'English Staff' => [UserRole::Staff, '/en/admin/orders'],
]);

test('admin navigation URLs stay inside the matched route family', function (string $path, array $expectedUrls): void {
    $actor = adminOrdersActor(UserRole::Admin);

    $this->actingAs($actor)
        ->get($path)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('adminNavigation.0.url', $expectedUrls[0])
            ->where('adminNavigation.1.url', $expectedUrls[1])
            ->where('adminNavigation.2.url', $expectedUrls[2])
            ->where('adminNavigation.3.url', $expectedUrls[3]));
})->with([
    'Canonical family' => ['/admin/orders', ['/admin', '/admin/orders', '/admin/customers', '/admin/security/mfa']],
    'Localized family' => ['/en/admin/orders', ['/en/admin', '/en/admin/orders', '/en/admin/customers', '/en/admin/security/mfa']],
]);

test('the orders route requires EnsureAdminMfa and can:orders.view middleware', function (): void {
    $route = Route::getRoutes()->getByName('admin.orders');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
        ->and($route?->gatherMiddleware())->toContain('can:orders.view');
});

test('the orders controller independently authorizes orders.view permission', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($customer);
    $request = App\Http\Requests\Admin\ListAdminOrders::create('/admin/orders', 'GET');
    $request->setUserResolver(fn (): User => $customer);

    expect(fn () => app(OrdersController::class)($request))
        ->toThrow(AuthorizationException::class);
});

test('orders request rejects unknown query parameters', function (): void {
    $admin = adminOrdersActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/orders?unknown_key=test')
        ->assertSessionHasErrors('query');
});

test('orders request validates all query filter boundaries', function (
    string $queryParam,
): void {
    $admin = adminOrdersActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get("/admin/orders?{$queryParam}")
        ->assertSessionHasErrors();
})->with([
    'invalid status' => ['status=invalid_status'],
    'invalid service' => ['service=invalid_service'],
    'invalid platform' => ['platform=invalid_platform'],
    'invalid payment_status' => ['payment_status=invalid_payment'],
    'invalid date_from format' => ['date_from=20-08-2026'],
    'invalid date_to format' => ['date_to=not-a-date'],
    'date_to before date_from' => ['date_from=2026-08-20&date_to=2026-08-10'],
    'invalid sort key' => ['sort=customer_name'],
    'invalid direction' => ['direction=sideways'],
    'invalid per_page' => ['per_page=30'],
    'search exceeds 100 chars' => ['search='.str_repeat('a', 101)],
]);

test('exact normalized search finds orders by order number, public ULID, customer ULID, email, or phone without wildcard matching', function (): void {
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'Saud',
        'last_name' => 'Al-Otaibi',
        'email' => 'saud.otaibi@example.test',
        'phone' => '+966501234567',
    ]);
    $order = adminOrdersOrder(
        $customer,
        OrderStatus::Received,
        now(),
        'AUT-SEARCH-1001',
        '01K5ADM1N0RD3R000000000001',
    );

    // Exact order number
    $results = app(ListAdminOrders::class)->paginate(['search' => 'AUT-SEARCH-1001']);
    expect($results['orders'])->toHaveCount(1)
        ->and($results['orders'][0]['id'])->toBe('01K5ADM1N0RD3R000000000001');

    // Trimming whitespace
    $results = app(ListAdminOrders::class)->paginate(['search' => '  AUT-SEARCH-1001  ']);
    expect($results['orders'])->toHaveCount(1);

    // Exact public ULID
    $results = app(ListAdminOrders::class)->paginate(['search' => '01K5ADM1N0RD3R000000000001']);
    expect($results['orders'])->toHaveCount(1);

    // Case-insensitive lowercase email comparison
    $results = app(ListAdminOrders::class)->paginate(['search' => 'SAUD.OTAIBI@EXAMPLE.TEST']);
    expect($results['orders'])->toHaveCount(1);

    // Exact phone
    $results = app(ListAdminOrders::class)->paginate(['search' => '+966501234567']);
    expect($results['orders'])->toHaveCount(1);

    // Exact customer public ID
    $results = app(ListAdminOrders::class)->paginate(['search' => $customer->public_id]);
    expect($results['orders'])->toHaveCount(1);

    // No wildcard substring match (e.g. 'AUT-SEARCH' must not match 'AUT-SEARCH-1001')
    $results = app(ListAdminOrders::class)->paginate(['search' => 'AUT-SEARCH']);
    expect($results['orders'])->toHaveCount(0);
});

test('date filters use inclusive UTC calendar days over orders.placed_at with sargable half-open boundaries', function (): void {
    $admin = adminOrdersActor(UserRole::Admin);

    // Target day: 2026-08-20
    $dayBefore = adminOrdersOrder($admin, OrderStatus::Received, Carbon::parse('2026-08-19 23:59:59', 'UTC'), 'AUT-DAY-BEFORE');
    $dayStart = adminOrdersOrder($admin, OrderStatus::Received, Carbon::parse('2026-08-20 00:00:00', 'UTC'), 'AUT-DAY-START');
    $dayMiddle = adminOrdersOrder($admin, OrderStatus::Received, Carbon::parse('2026-08-20 14:30:00', 'UTC'), 'AUT-DAY-MIDDLE');
    $dayEnd = adminOrdersOrder($admin, OrderStatus::Received, Carbon::parse('2026-08-20 23:59:59', 'UTC'), 'AUT-DAY-END');
    $dayAfter = adminOrdersOrder($admin, OrderStatus::Received, Carbon::parse('2026-08-21 00:00:00', 'UTC'), 'AUT-DAY-AFTER');

    $results = app(ListAdminOrders::class)->paginate([
        'date_from' => '2026-08-20',
        'date_to' => '2026-08-20',
    ]);

    $numbers = array_column($results['orders'], 'orderNumber');
    expect($numbers)->toContain('AUT-DAY-START')
        ->and($numbers)->toContain('AUT-DAY-MIDDLE')
        ->and($numbers)->toContain('AUT-DAY-END')
        ->and($numbers)->not->toContain('AUT-DAY-BEFORE')
        ->and($numbers)->not->toContain('AUT-DAY-AFTER');
});

test('same-item service and platform semantics require both filters to match on the same order item', function (): void {
    $admin = adminOrdersActor(UserRole::Admin);

    // Order 1 has an item with Coins on PlayStation
    $order1 = adminOrdersOrder($admin, OrderStatus::Received, now(), 'AUT-MATCH-1');
    adminOrdersItem($order1, ServiceType::Coins, Platform::PlayStation);

    // Order 2 has item A (Coins, Xbox) and item B (SBC, PlayStation) -> both service and platform exist on order, but NOT on the same item
    $order2 = adminOrdersOrder($admin, OrderStatus::Received, now(), 'AUT-SPLIT-2');
    adminOrdersItem($order2, ServiceType::Coins, Platform::Xbox);
    adminOrdersItem($order2, ServiceType::Sbc, Platform::PlayStation);

    // Filter by Coins + PlayStation
    $results = app(ListAdminOrders::class)->paginate([
        'service' => 'coins',
        'platform' => 'playstation',
    ]);

    $numbers = array_column($results['orders'], 'orderNumber');
    expect($numbers)->toContain('AUT-MATCH-1')
        ->and($numbers)->not->toContain('AUT-SPLIT-2');
});

test('latest payment semantics project and filter by the latest payment by descending internal ID', function (): void {
    $admin = adminOrdersActor(UserRole::Admin);

    // Order with multiple payments: #1 Failed, #2 Paid -> latest is Paid
    $order1 = adminOrdersOrder($admin, OrderStatus::Completed, now(), 'AUT-PAY-MULTI');
    adminOrdersPayment($order1, PaymentStatus::Failed, 1000);
    adminOrdersPayment($order1, PaymentStatus::Paid, 1000);

    // Order with no payments
    $order2 = adminOrdersOrder($admin, OrderStatus::PendingPayment, now(), 'AUT-PAY-NONE');

    // Filter by paid
    $paidResults = app(ListAdminOrders::class)->paginate(['payment_status' => 'paid']);
    $paidNumbers = array_column($paidResults['orders'], 'orderNumber');
    expect($paidNumbers)->toContain('AUT-PAY-MULTI')
        ->and($paidNumbers)->not->toContain('AUT-PAY-NONE');

    // Filter by failed should not match order1 because its latest payment is paid
    $failedResults = app(ListAdminOrders::class)->paginate(['payment_status' => 'failed']);
    $failedNumbers = array_column($failedResults['orders'], 'orderNumber');
    expect($failedNumbers)->not->toContain('AUT-PAY-MULTI');

    // Projection test
    $allResults = app(ListAdminOrders::class)->paginate(['sort' => 'order_number', 'direction' => 'asc']);
    $rowMulti = collect($allResults['orders'])->firstWhere('orderNumber', 'AUT-PAY-MULTI');
    $rowNone = collect($allResults['orders'])->firstWhere('orderNumber', 'AUT-PAY-NONE');

    expect($rowMulti['latestPaymentStatus'])->toBe('paid')
        ->and($rowNone['latestPaymentStatus'])->toBeNull();
});

test('sorting allowlist supports placed_at, total, and order_number with deterministic tie-breaks', function (): void {
    $admin = adminOrdersActor(UserRole::Admin);

    $order1 = adminOrdersOrder($admin, OrderStatus::Received, Carbon::parse('2026-08-20 10:00:00', 'UTC'), 'AUT-ORDER-1');
    $order1->forceFill(['total_halalah' => 10000])->save();

    $order2 = adminOrdersOrder($admin, OrderStatus::Received, Carbon::parse('2026-08-20 12:00:00', 'UTC'), 'AUT-ORDER-2');
    $order2->forceFill(['total_halalah' => 30000])->save();

    $order3 = adminOrdersOrder($admin, OrderStatus::Received, Carbon::parse('2026-08-20 11:00:00', 'UTC'), 'AUT-ORDER-3');
    $order3->forceFill(['total_halalah' => 20000])->save();
    $order4 = adminOrdersOrder($admin, OrderStatus::Received, Carbon::parse('2026-08-20 12:00:00', 'UTC'), 'AUT-ORDER-0');
    $order4->forceFill(['total_halalah' => 30000])->save();

    $totalAsc = app(ListAdminOrders::class)->paginate(['sort' => 'total', 'direction' => 'asc']);
    expect(array_column($totalAsc['orders'], 'orderNumber'))->toBe(['AUT-ORDER-1', 'AUT-ORDER-3', 'AUT-ORDER-2', 'AUT-ORDER-0']);

    $placedDesc = app(ListAdminOrders::class)->paginate(['sort' => 'placed_at', 'direction' => 'desc']);
    expect(array_column($placedDesc['orders'], 'orderNumber'))->toBe(['AUT-ORDER-0', 'AUT-ORDER-2', 'AUT-ORDER-3', 'AUT-ORDER-1']);

    $numberAsc = app(ListAdminOrders::class)->paginate(['sort' => 'order_number', 'direction' => 'asc']);
    expect(array_column($numberAsc['orders'], 'orderNumber'))->toBe(['AUT-ORDER-0', 'AUT-ORDER-1', 'AUT-ORDER-2', 'AUT-ORDER-3']);
});

test('pagination returns exact meta and supports allowed per_page sizes', function (
    int $perPage,
): void {
    $admin = adminOrdersActor(UserRole::Admin);

    foreach (range(1, 20) as $i) {
        adminOrdersOrder($admin, OrderStatus::Received, now()->subMinutes($i), sprintf('AUT-PAGE-%02d', $i));
    }

    $results = app(ListAdminOrders::class)->paginate(['per_page' => $perPage, 'page' => 1]);

    expect($results['pagination'])->toBe([
        'currentPage' => 1,
        'lastPage' => (int) ceil(20 / $perPage),
        'perPage' => $perPage,
        'total' => 20,
        'from' => 1,
        'to' => min(20, $perPage),
    ])->and(count($results['orders']))->toBe(min(20, $perPage));
})->with([15, 25, 50, 100]);

test('order list presenter projects only safe row DTO and never leaks secrets or internal IDs', function (): void {
    $admin = adminOrdersActor(UserRole::Admin);
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'Privacy',
        'last_name' => 'Tester',
        'email' => 'privacy.test@example.test',
        'password' => 'HashedPasswordMustNotLeak',
    ]);
    $order = adminOrdersOrder($customer, OrderStatus::Received, now(), 'AUT-PRIVACY-1', '01K5ADM1NPR1VACY0000000001');
    $order->forceFill(['currency' => 'USD'])->save();
    adminOrdersItem($order, ServiceType::Coins, Platform::PlayStation);
    adminOrdersPayment($order, PaymentStatus::Paid, 5000);

    $page = app(AdminOrdersPage::class)->for($admin, 'en', [
        'sort' => 'placed_at',
        'direction' => 'desc',
        'per_page' => 15,
        'page' => 1,
    ]);

    expect($page['orders'])->toHaveCount(1);
    $row = $page['orders'][0];

    expect(array_keys($row))->toBe([
        'id',
        'orderNumber',
        'customer',
        'status',
        'serviceTypes',
        'platforms',
        'itemCount',
        'latestPaymentStatus',
        'total',
        'placedAt',
    ])->and(array_keys($row['customer']))->toBe(['id', 'name', 'email', 'phone'])
        ->and(array_keys($row['total']))->toBe(['amountMinor', 'currency'])
        ->and($row)->toMatchArray([
            'id' => '01K5ADM1NPR1VACY0000000001',
            'orderNumber' => 'AUT-PRIVACY-1',
            'customer' => [
                'id' => $customer->public_id,
                'name' => 'Privacy Tester',
                'email' => 'privacy.test@example.test',
                'phone' => null,
            ],
            'status' => 'received',
            'serviceTypes' => ['coins'],
            'platforms' => ['playstation'],
            'itemCount' => 1,
            'latestPaymentStatus' => 'paid',
        ])->and($row['total']['currency'])->toBe('USD')
        ->and($row['total']['amountMinor'])->toBeString();

    $serialized = json_encode($page['orders'], JSON_THROW_ON_ERROR);
    foreach (['password', 'two_factor', 'provider_payment_id', 'idempotency_key', 'provider_metadata', 'encrypted_payload', 'HashedPasswordMustNotLeak'] as $forbidden) {
        expect($serialized)->not->toContain($forbidden);
    }
});

test('order queries stay bounded with no N+1 for current-page item summaries and payments', function (): void {
    $admin = adminOrdersActor(UserRole::Admin);

    foreach (range(1, 10) as $i) {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = adminOrdersOrder($customer, OrderStatus::Received, now()->subHours($i), "AUT-BOUNDED-{$i}");
        adminOrdersItem($order, ServiceType::Coins, Platform::PlayStation);
        adminOrdersItem($order, ServiceType::Sbc, Platform::PlayStation);
        adminOrdersPayment($order, PaymentStatus::Paid, 5000);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(ListAdminOrders::class)->paginate(['per_page' => 15, 'page' => 1]);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($queries))->toBeLessThanOrEqual(5);
});

function createAdminOrdersLifecycleTables(): void
{
    Schema::create('order_items', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('order_id');
        $table->string('service_type');
        $table->string('platform');
    });
    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->timestamp('placed_at')->nullable();
        $table->bigInteger('total_halalah')->default(0);
    });
    Schema::create('payments', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('order_id');
    });
}

function assertAdminOrdersLifecycleIndexes(bool $expected): void
{
    expect(Schema::hasIndex('order_items', 'idx_order_items_admin_service_order'))->toBe($expected)
        ->and(Schema::hasIndex('order_items', 'idx_order_items_admin_platform_order'))->toBe($expected)
        ->and(Schema::hasIndex('orders', 'idx_orders_admin_placed_sort'))->toBe($expected)
        ->and(Schema::hasIndex('orders', 'idx_orders_admin_total_sort'))->toBe($expected)
        ->and(Schema::hasIndex('payments', 'idx_payments_admin_order_id_lookup'))->toBe($expected);
}

function adminOrdersActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINORDERSTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}

function adminOrdersOrder(
    User $owner,
    OrderStatus $status,
    DateTimeInterface $placedAt,
    string $number,
    ?string $publicId = null,
): Order {
    $order = Order::factory()->for($owner)->make([
        'order_number' => $number,
        'status' => $status,
        'placed_at' => $placedAt,
        'created_at' => $placedAt,
        'updated_at' => $placedAt,
    ]);

    if ($publicId !== null) {
        $order->usePublicIdForImport($publicId);
    }

    $order->save();

    return $order;
}

function adminOrdersItem(
    Order $order,
    ServiceType $serviceType,
    Platform $platform,
): void {
    $order->items()->create([
        'sku' => 'AUT-SKU-'.Str::random(6),
        'name_ar' => 'خدمة',
        'name_en' => 'Service',
        'service_type' => $serviceType,
        'platform' => $platform,
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);
}

function adminOrdersPayment(
    Order $order,
    PaymentStatus $status,
    int $amountHalalah,
): void {
    $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => (string) Str::uuid(),
        'status' => $status,
        'currency' => 'SAR',
        'amount_halalah' => $amountHalalah,
        'captured_halalah' => $status === PaymentStatus::Paid ? $amountHalalah : 0,
        'refunded_halalah' => 0,
        'idempotency_key' => (string) Str::uuid(),
        'paid_at' => $status === PaymentStatus::Paid ? now() : null,
    ]);
}
