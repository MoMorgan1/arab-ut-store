<?php

use App\Admin\Presenters\AdminOverviewPage;
use App\Admin\Presenters\AdminShell;
use App\Admin\Queries\ReadAdminOverview;
use App\Admin\Support\CapturedRevenueAmount;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\Order;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('the Admin overview index migration rolls back and reapplies on disposable SQLite', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'arab-ut-admin-overview-');

    if ($databasePath === false) {
        throw new RuntimeException('Unable to create the disposable Admin overview SQLite database.');
    }

    config(['database.connections.admin_overview_lifecycle' => [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);
    DB::purge('admin_overview_lifecycle');
    DB::setDefaultConnection('admin_overview_lifecycle');

    try {
        createAdminOverviewLifecycleTables();
        $migration = require database_path('migrations/2026_08_21_000002_add_admin_overview_indexes.php');

        $migration->up();
        assertAdminOverviewLifecycleIndexes(true);

        $migration->down();
        assertAdminOverviewLifecycleIndexes(false);
        expect(Schema::hasIndex('orders', ['status']))->toBeTrue()
            ->and(Schema::hasIndex('payments', ['status']))->toBeTrue()
            ->and(Schema::hasIndex('refunds', ['status']))->toBeTrue();

        $migration->up();
        assertAdminOverviewLifecycleIndexes(true);
    } finally {
        DB::disconnect('admin_overview_lifecycle');
        DB::purge('admin_overview_lifecycle');
        DB::setDefaultConnection($originalConnection);
        unlink($databasePath);
    }
});

test('the overview returns literal bounded operational metrics and the oldest unresolved order', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $admin = adminOverviewActor(UserRole::Admin);
    seedLiteralAdminOverviewFixture($admin);

    $overview = app(ReadAdminOverview::class)->for($admin, 7);

    expect($overview)->toMatchArray([
        'rangeDays' => 7,
        'orders' => [
            'received' => 1,
            'inProgress' => 1,
            'waitingForCustomer' => 1,
        ],
        'payments' => ['pending' => 1, 'failed' => 1],
        'refunds' => ['failed' => 1],
        'capturedRevenue' => ['amountMinor' => '1250', 'currency' => 'SAR'],
        'previousCapturedRevenue' => ['amountMinor' => '0', 'currency' => 'SAR'],
        'totalOrders' => ['current' => 4, 'previous' => 1],
        'attentionCount' => 3,
        'oldestUnresolvedOrder' => [
            'id' => '01K5ADM1N0V3RV13W000000001',
            'number' => 'AUT-OLDEST-1001',
            'status' => 'received',
            'placedAt' => '2026-08-20T10:00:00+00:00',
        ],
    ])->and($overview['recentAuditEvents'])->toHaveCount(5)
        ->and($overview['revenueTrend'])->toHaveCount(8)
        ->and($overview['orderStatusDistribution'])->toHaveCount(7)
        ->and($overview['recentOrders'])->toHaveCount(4);
});

test('revenue trend points zero-fill all touched UTC dates in the rolling window', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $admin = adminOverviewActor(UserRole::Admin);
    $order = adminOverviewOrder($admin, OrderStatus::Completed, now()->subDay(), 'AUT-REV-TREND-1');
    adminOverviewPayment($order, PaymentStatus::Paid, 500, Carbon::parse('2026-08-20 15:00:00', 'UTC'));

    $overview7 = app(ReadAdminOverview::class)->for($admin, 7);
    $overview30 = app(ReadAdminOverview::class)->for($admin, 30);

    expect($overview7['revenueTrend'])->toHaveCount(8)
        ->and($overview7['revenueTrend'][0]['date'])->toBe('2026-08-14')
        ->and($overview7['revenueTrend'][7]['date'])->toBe('2026-08-21')
        ->and(collect($overview7['revenueTrend'])->firstWhere('date', '2026-08-20'))->toBe([
            'date' => '2026-08-20',
            'amountMinor' => '500',
            'currency' => 'SAR',
        ])
        ->and(collect($overview7['revenueTrend'])->firstWhere('date', '2026-08-14')['amountMinor'])->toBe('0')
        ->and($overview30['revenueTrend'])->toHaveCount(31)
        ->and($overview30['revenueTrend'][0]['date'])->toBe('2026-07-22')
        ->and($overview30['revenueTrend'][30]['date'])->toBe('2026-08-21');
});

test('order status distribution includes all canonical OrderStatus cases in stable order with zero counts', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $admin = adminOverviewActor(UserRole::Admin);
    adminOverviewOrder($admin, OrderStatus::Received, now()->subDay(), 'AUT-DIST-1');
    adminOverviewOrder($admin, OrderStatus::Completed, now()->subDays(2), 'AUT-DIST-2');

    $distribution = app(ReadAdminOverview::class)->for($admin, 7)['orderStatusDistribution'];

    expect(array_column($distribution, 'status'))->toBe([
        'pending_payment',
        'received',
        'in_progress',
        'waiting_for_customer',
        'completed',
        'cancelled',
        'refunded',
    ])->and(collect($distribution)->firstWhere('status', 'received')['count'])->toBe(1)
        ->and(collect($distribution)->firstWhere('status', 'completed')['count'])->toBe(1)
        ->and(collect($distribution)->firstWhere('status', 'cancelled')['count'])->toBe(0)
        ->and(collect($distribution)->firstWhere('status', 'pending_payment')['count'])->toBe(0);
});

test('recent orders returns at most five orders ordered newest first without customer PII or provider payloads', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $admin = adminOverviewActor(UserRole::Admin);

    foreach (range(1, 7) as $i) {
        $order = adminOverviewOrder(
            $admin,
            OrderStatus::Received,
            now()->subHours(10 - $i),
            "AUT-RECENT-{$i}",
            sprintf('01K5ADM1N0000000000000000%d', $i),
        );
        $order->forceFill(['total_halalah' => 1500 * $i])->save();
    }

    $recent = app(ReadAdminOverview::class)->for($admin, 7)['recentOrders'];

    expect($recent)->toHaveCount(5)
        ->and($recent[0]['number'])->toBe('AUT-RECENT-7')
        ->and($recent[0]['id'])->toBe('01K5ADM1N00000000000000007')
        ->and($recent[0]['total'])->toBe(['amountMinor' => '10500', 'currency' => 'SAR'])
        ->and(array_keys($recent[0]))->toBe(['id', 'number', 'status', 'placedAt', 'total']);

    $serialized = json_encode($recent, JSON_THROW_ON_ERROR);
    foreach (['email', 'user_id', 'provider', 'metadata', 'password'] as $forbidden) {
        expect($serialized)->not->toContain($forbidden);
    }
});

test('new customers counts only customer roles in rolling windows', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $admin = adminOverviewActor(UserRole::Admin);

    User::factory()->create([
        'role' => UserRole::Customer,
        'created_at' => now()->subDay(),
    ]);
    User::factory()->create([
        'role' => UserRole::Staff,
        'created_at' => now()->subDay(),
    ]);
    User::factory()->create([
        'role' => UserRole::Admin,
        'created_at' => now()->subDay(),
    ]);
    User::factory()->create([
        'role' => UserRole::Customer,
        'created_at' => now()->subDays(10),
    ]);
    User::factory()->create([
        'role' => UserRole::Customer,
        'created_at' => now()->subDays(20),
    ]);

    $counts = app(ReadAdminOverview::class)->for($admin, 7)['newCustomers'];

    expect($counts)->toBe([
        'current' => 1,
        'previous' => 1,
    ]);
});

test('the overview includes the exact lower date boundary and excludes older or future activity', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $admin = adminOverviewActor(UserRole::Admin);
    adminOverviewOrder($admin, OrderStatus::Received, now()->subDays(7), 'AUT-BOUNDARY-1');
    adminOverviewOrder($admin, OrderStatus::Received, now()->subDays(7)->subSecond(), 'AUT-OLDER-1');
    adminOverviewOrder($admin, OrderStatus::Received, now()->addSecond(), 'AUT-FUTURE-1');

    $overview = app(ReadAdminOverview::class)->for($admin, 7);

    expect($overview['orders']['received'])->toBe(1)
        ->and($overview['totalOrders']['current'])->toBe(1)
        ->and($overview['totalOrders']['previous'])->toBe(1);
});

test('the main overview queries use the named search indexes instead of full table scans on SQLite', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $admin = adminOverviewActor(UserRole::Admin);
    seedLiteralAdminOverviewFixture($admin);
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(ReadAdminOverview::class)->for($admin, 7);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $orderMetricsPlan = explainAdminOverviewQuery($queries, ' as received');
    $paymentPlan = explainAdminOverviewQuery($queries, ' as pending');
    $oldestOrderPlan = explainAdminOverviewQuery($queries, ' as activity_at');

    expect($orderMetricsPlan)->toContain('idx_orders_admin_status_activity')
        ->and(strtoupper($orderMetricsPlan))->not->toContain('SCAN ORDERS')
        ->and($paymentPlan)->toContain('idx_payments_admin_status_paid')
        ->and(strtoupper($paymentPlan))->not->toContain('SCAN PAYMENTS')
        ->and($oldestOrderPlan)->toContain('USING INDEX')
        ->and(strtoupper($oldestOrderPlan))->not->toContain('SCAN ORDERS');
});

test('captured revenue includes only paid and refunded payments in the selected paid window', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $admin = adminOverviewActor(UserRole::Admin);
    $order = adminOverviewOrder($admin, OrderStatus::Completed, now()->subDay(), 'AUT-REVENUE-1');

    adminOverviewPayment($order, PaymentStatus::Paid, 400, now()->subDays(30));
    adminOverviewPayment($order, PaymentStatus::Refunded, 300, now()->subDays(2));
    adminOverviewPayment($order, PaymentStatus::PartiallyRefunded, 900, now()->subDay());
    adminOverviewPayment($order, PaymentStatus::Paid, 800, now()->subDays(30)->subSecond());
    adminOverviewPayment($order, PaymentStatus::Paid, 700, now()->addSecond());

    expect(app(ReadAdminOverview::class)->for($admin, 30)['capturedRevenue'])->toBe([
        'amountMinor' => '700',
        'currency' => 'SAR',
    ]);
});

test('captured revenue normalization preserves exact database decimal strings', function (
    int|string|null $databaseAmount,
    string $expected,
): void {
    expect(app(CapturedRevenueAmount::class)->fromDatabase($databaseAmount))->toBe($expected);
})->with([
    'empty aggregate' => [null, '0'],
    'portable SQLite integer' => [1250, '1250'],
    'MariaDB decimal beyond PHP integer range' => ['18446744073709551614', '18446744073709551614'],
]);

test('Staff receive no global audit events while Admin receive at most five safe event fields', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $admin = adminOverviewActor(UserRole::Admin);
    $staff = adminOverviewActor(UserRole::Staff);

    foreach (range(1, 7) as $sequence) {
        StaffAuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'action' => "orders.event_{$sequence}",
            'metadata' => [
                'case_reference' => "CASE-{$sequence}",
                'nested' => ['provider_payload' => "provider-secret-{$sequence}"],
            ],
            'ip_address' => '2001:db8::1',
            'created_at' => now()->subSeconds($sequence),
        ]);
    }

    $adminEvents = app(ReadAdminOverview::class)->for($admin, 7)['recentAuditEvents'];
    $staffEvents = app(ReadAdminOverview::class)->for($staff, 7)['recentAuditEvents'];

    expect($adminEvents)->toHaveCount(5)
        ->and(array_keys($adminEvents[0]))->toBe(['id', 'action', 'createdAt'])
        ->and($staffEvents)->toBeNull();

    $serializedEvents = json_encode($adminEvents, JSON_THROW_ON_ERROR);
    foreach (['provider-secret', 'metadata', 'ip_address'] as $forbiddenField) {
        expect($serializedEvents)->not->toContain($forbiddenField);
    }
});

test('the overview query count stays bounded and its selects omit secret and provider payload columns', function (
    UserRole $role,
    int $maximumQueries,
): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00', 'UTC'));
    $actor = adminOverviewActor($role);
    seedLiteralAdminOverviewFixture($actor);
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(ReadAdminOverview::class)->for($actor, 7);

    $queries = DB::getQueryLog();
    $sql = strtolower(implode("\n", array_column($queries, 'query')));
    DB::disableQueryLog();

    expect(count($queries))->toBeLessThanOrEqual($maximumQueries);

    foreach (['encrypted_payload', 'provider_metadata', 'two_factor_secret', 'two_factor_recovery_codes', 'password', 'select *'] as $forbiddenColumn) {
        expect($sql)->not->toContain($forbiddenColumn);
    }
})->with([
    'Admin has one bounded audit query' => [UserRole::Admin, 10],
    'Staff skips the global audit query' => [UserRole::Staff, 9],
]);

test('the Admin shell exposes only safe identity exact permissions and implemented localized navigation', function (
    UserRole $role,
    string $locale,
    array $expectedPermissions,
    array $expectedUrls,
): void {
    $actor = adminOverviewActor($role, $locale);

    $shell = app(AdminShell::class)->for($actor, $locale);

    expect($shell['adminIdentity'])->toBe(['name' => $actor->name, 'role' => $role->value])
        ->and($shell['permissions'])->toBe($expectedPermissions)
        ->and(array_column($shell['adminNavigation'], 'key'))->toBe(
            $role === UserRole::Admin
                ? ['overview', 'orders', 'customers', 'marketing', 'products', 'settings']
                : ['overview', 'orders', 'settings'],
        )
        ->and(array_column($shell['adminNavigation'], 'url'))->toBe($expectedUrls)
        ->and($shell['logoutUrl'])->toBe('/logout');

    $serializedShell = json_encode($shell, JSON_THROW_ON_ERROR);
    foreach ([$actor->email, 'password', 'two_factor', 'ordersUrl', 'chat'] as $forbiddenField) {
        expect($serializedShell)->not->toContain($forbiddenField);
    }
})->with([
    'English Admin' => [
        UserRole::Admin,
        'en',
        [
            'dashboard.view',
            'orders.view',
            'orders.update',
            'orders.cancel',
            'orders.refund',
            'order_credentials.view',
            'customers.view',
            'customers.update_status',
            'customers.update_contact',
            'payments.view',
            'payments.refund',
            'wallet.view',
            'wallet.adjust',
            'catalog.view',
            'catalog.manage',
            'audit.view',
            'staff.view',
            'staff.manage',
            'settings.view',
            'settings.manage',
            'marketing.view',
            'marketing.manage',
        ],
        ['/admin', '/admin/orders', '/admin/customers', '/admin/marketing/coupons', '/admin/products', '/admin/settings'],
    ],
    'English Staff' => [
        UserRole::Staff,
        'en',
        [
            'dashboard.view',
            'orders.view',
            'orders.update',
            'orders.cancel',
            'order_credentials.view',
        ],
        ['/admin', '/admin/orders', '/admin/settings'],
    ],
]);

test('the page presenter composes exact localized range URLs and active state', function (
    string $locale,
    int $days,
    array $expectedOptions,
): void {
    $admin = adminOverviewActor(UserRole::Admin, $locale);

    $page = app(AdminOverviewPage::class)->for($admin, $locale, $days);

    expect($page['locale'])->toBe($locale)
        ->and($page['direction'])->toBe($locale === 'en' ? 'ltr' : 'rtl')
        ->and($page['overview']['rangeDays'])->toBe($days)
        ->and($page['rangeOptions'])->toBe($expectedOptions);
})->with([
    'English seven days' => ['en', 7, [
        ['days' => 7, 'label' => 'Last 7 days', 'url' => '/admin?range=7', 'active' => true],
        ['days' => 30, 'label' => 'Last 30 days', 'url' => '/admin?range=30', 'active' => false],
    ]],
    'English thirty days' => ['en', 30, [
        ['days' => 7, 'label' => 'Last 7 days', 'url' => '/admin?range=7', 'active' => false],
        ['days' => 30, 'label' => 'Last 30 days', 'url' => '/admin?range=30', 'active' => true],
    ]],
]);

test('confirmed privileged actors can open localized private overview routes', function (
    UserRole $role,
    string $locale,
    string $path,
): void {
    $actor = adminOverviewActor($role, $locale);

    $this->actingAs($actor)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/overview', false)
            ->where('auth', null)
            ->where('locale', 'en')
            ->where('direction', 'ltr')
            ->where('overview.rangeDays', 30));
})->with([
    'Canonical Admin' => [UserRole::Admin, 'en', '/admin?range=30'],
    'English Staff' => [UserRole::Staff, 'en', '/en/admin?range=30'],
]);

test('guests and nonprivileged accounts cannot enter the Admin overview', function (): void {
    $this->get('/admin')->assertRedirect('/en/login');

    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($customer)->get('/admin')->assertForbidden();

    $inactiveStaff = adminOverviewActor(UserRole::Staff);
    $inactiveStaff->forceFill(['is_active' => false])->save();
    $this->actingAs($inactiveStaff)->get('/admin')->assertForbidden();
});

test('the overview controller independently authorizes dashboard permission', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($customer);
    $request = Request::create('/admin', 'GET');
    $request->setUserResolver(fn (): User => $customer);

    expect(fn () => app(OverviewController::class)($request))
        ->toThrow(AuthorizationException::class);
});

test('the overview controller authorizes the actor resolved from its request', function (): void {
    $ambientAdmin = adminOverviewActor(UserRole::Admin);
    $resolvedCustomer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($ambientAdmin);
    $request = Request::create('/admin', 'GET');
    $request->setUserResolver(fn (): User => $resolvedCustomer);

    expect(fn () => app(OverviewController::class)($request))
        ->toThrow(AuthorizationException::class);
});

test('overview routes reject unsupported ranges and retain the ordinary confirmed MFA boundary', function (): void {
    $admin = adminOverviewActor(UserRole::Admin);
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin)->get('/admin')->assertRedirect('/admin/settings');

    $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->actingAs($admin)
        ->get('/admin?range=8')
        ->assertSessionHasErrors('range');

    $route = Route::getRoutes()->getByName('admin.overview');
    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(EnsureAdminMfa::class);
});

function createAdminOverviewLifecycleTables(): void
{
    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->index();
        $table->timestamp('placed_at')->nullable();
    });
    Schema::create('payments', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->index();
        $table->timestamp('paid_at')->nullable();
    });
    Schema::create('refunds', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->index();
        $table->timestamp('created_at')->nullable();
    });
    Schema::create('staff_audit_logs', function (Blueprint $table): void {
        $table->id();
        $table->timestamp('created_at')->nullable();
    });
}

function assertAdminOverviewLifecycleIndexes(bool $expected): void
{
    expect(Schema::hasIndex('orders', 'idx_orders_admin_status_activity'))->toBe($expected)
        ->and(Schema::hasIndex('payments', 'idx_payments_admin_status_paid'))->toBe($expected)
        ->and(Schema::hasIndex('refunds', 'idx_refunds_admin_status_created'))->toBe($expected)
        ->and(Schema::hasIndex('staff_audit_logs', 'idx_staff_audits_admin_created'))->toBe($expected);
}

/** @param list<array{query: string, bindings: array<int, mixed>, time: float}> $queries */
function explainAdminOverviewQuery(array $queries, string $marker): string
{
    $loggedQuery = collect($queries)->first(
        fn (array $query): bool => str_contains(strtolower($query['query']), $marker),
    );

    if (! is_array($loggedQuery)) {
        throw new RuntimeException("Missing overview query marker [{$marker}].");
    }

    return collect(DB::select('EXPLAIN QUERY PLAN '.$loggedQuery['query'], $loggedQuery['bindings']))
        ->pluck('detail')
        ->implode(' | ');
}

function adminOverviewActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINOVERVIEWTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}

function adminOverviewOrder(
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

function adminOverviewPayment(
    Order $order,
    PaymentStatus $status,
    int $capturedHalalah,
    ?DateTimeInterface $paidAt,
    ?DateTimeInterface $createdAt = null,
): void {
    $createdAt ??= $paidAt ?? now();
    $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => (string) Str::uuid(),
        'status' => $status,
        'currency' => 'SAR',
        'amount_halalah' => $capturedHalalah,
        'captured_halalah' => $capturedHalalah,
        'refunded_halalah' => $status === PaymentStatus::Refunded ? $capturedHalalah : 0,
        'idempotency_key' => (string) Str::uuid(),
        'provider_metadata' => ['providerPayload' => 'must-never-load'],
        'paid_at' => $paidAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function seedLiteralAdminOverviewFixture(User $actor): void
{
    $oldest = adminOverviewOrder(
        $actor,
        OrderStatus::Received,
        Carbon::parse('2026-08-20 10:00:00', 'UTC'),
        'AUT-OLDEST-1001',
        '01K5ADM1N0V3RV13W000000001',
    );
    adminOverviewOrder($actor, OrderStatus::InProgress, Carbon::parse('2026-08-20 11:00:00', 'UTC'), 'AUT-INPROGRESS-1002');
    adminOverviewOrder($actor, OrderStatus::WaitingForCustomer, Carbon::parse('2026-08-20 12:00:00', 'UTC'), 'AUT-WAITING-1003');
    adminOverviewOrder($actor, OrderStatus::Completed, now()->subDays(7)->subSecond(), 'AUT-OLD-1004');
    adminOverviewOrder($actor, OrderStatus::Completed, now()->subDay(), 'AUT-COMPLETED-1005');

    adminOverviewPayment($oldest, PaymentStatus::Pending, 0, null, now()->subDay());
    adminOverviewPayment($oldest, PaymentStatus::Failed, 0, null, now()->subDays(2));
    adminOverviewPayment($oldest, PaymentStatus::Paid, 1000, now()->subDay());
    adminOverviewPayment($oldest, PaymentStatus::Refunded, 250, now()->subDays(2));
    adminOverviewPayment($oldest, PaymentStatus::PartiallyRefunded, 500, now()->subDay());

    $oldest->refunds()->create([
        'payment_id' => null,
        'created_by_user_id' => $actor->id,
        'method' => 'paylink',
        'status' => 'failed',
        'amount_halalah' => 250,
        'reason_ar' => 'Ø³Ø¨Ø¨ Ø§Ø®ØªØ¨Ø§Ø±ÙŠ Ø¢Ù…Ù†',
        'reason_en' => 'Safe synthetic reason',
        'provider_metadata' => ['providerPayload' => 'must-never-load'],
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    foreach (range(1, 7) as $sequence) {
        StaffAuditLog::query()->create([
            'actor_user_id' => $actor->id,
            'action' => "orders.fixture_{$sequence}",
            'metadata' => ['case_reference' => "CASE-{$sequence}"],
            'created_at' => now()->subMinutes($sequence),
        ]);
    }
}
