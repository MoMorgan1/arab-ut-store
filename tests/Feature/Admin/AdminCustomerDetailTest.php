<?php

use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\WalletEntryType;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests and nonprivileged accounts cannot open the Admin customer detail', function (): void {
    $customer = createCustomerDetailFixture();

    $this->get("/admin/customers/{$customer->public_id}")->assertRedirect('/en/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get("/admin/customers/{$customer->public_id}")->assertForbidden();
    }

    $inactiveStaff = createCustomerDetailActor(UserRole::Staff);
    $inactiveStaff->forceFill(['is_active' => false])->save();
    $this->actingAs($inactiveStaff)->get("/admin/customers/{$customer->public_id}")->assertForbidden();
});

test('staff users are forbidden from viewing customer detail', function (): void {
    $staff = createCustomerDetailActor(UserRole::Staff);
    $customer = createCustomerDetailFixture();

    $this->actingAs($staff)->get("/admin/customers/{$customer->public_id}")->assertForbidden();
});

test('unconfirmed MFA admin users are redirected to MFA setup', function (): void {
    $admin = createCustomerDetailActor(UserRole::Admin);
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();
    $customer = createCustomerDetailFixture();

    $this->actingAs($admin)
        ->get("/admin/customers/{$customer->public_id}")
        ->assertRedirect('/admin/settings');
});

test('confirmed Admin can open localized private customer detail routes', function (string $prefix): void {
    $admin = createCustomerDetailActor(UserRole::Admin);
    $customer = createCustomerDetailFixture();

    $this->actingAs($admin)
        ->get("{$prefix}/customers/{$customer->public_id}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/customers/show', false)
            ->where('auth', null)
            ->where('locale', 'en')
            ->where('direction', 'ltr')
            ->where('customer.id', (string) $customer->public_id)
            ->where('customer.name', $customer->name)
            ->where('customer.email', $customer->email)
            ->where('customer.isActive', true)
            ->has('customer.updatedAt')
            ->has('customer.ordersSummary')
            ->has('customer.recentOrders')
            ->has('customer.walletSummary')
            ->has('customer.recentWalletEntries')
            ->has('statusUrl')
            ->has('contactUrl')
            ->has('walletAdjustUrl')
            ->has('confirmPasswordUrl')
        );
})->with([
    'default prefix' => ['/admin'],
    'localized prefix' => ['/en/admin'],
]);

test('unknown customer public ID returns 404', function (): void {
    $admin = createCustomerDetailActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/customers/01K5UNKNOWN0000000000000000')
        ->assertNotFound();
});

test('attempting to view non-customer accounts via customer detail returns 404', function (): void {
    $admin = createCustomerDetailActor(UserRole::Admin);
    $staff = createCustomerDetailActor(UserRole::Staff);

    // Fail closed: querying a Staff or Admin user ID returns 404, not 403 or data leak
    $this->actingAs($admin)
        ->get("/admin/customers/{$staff->public_id}")
        ->assertNotFound();

    $this->actingAs($admin)
        ->get("/admin/customers/{$admin->public_id}")
        ->assertNotFound();
});

test('customer detail route requires EnsureAdminMfa and can:customers.view middleware', function (): void {
    $route = Route::getRoutes()->getByName('admin.customers.show');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
        ->and($route?->gatherMiddleware())->toContain('can:customers.view');
});

test('customer detail props never leak passwords or sensitive tokens', function (): void {
    $admin = createCustomerDetailActor(UserRole::Admin);
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'email' => 'customer-detail-privacy-test@example.com',
        'password' => 'CustomerDetailSecretPassword!99',
    ]);

    $response = $this->actingAs($admin)->get("/admin/customers/{$customer->public_id}");
    $content = $response->getContent();

    $response->assertOk();
    expect($content)->not->toContain('CustomerDetailSecretPassword!99')
        ->and($content)->not->toContain('two_factor_secret')
        ->and($content)->not->toContain('two_factor_recovery_codes')
        ->and($content)->not->toContain('remember_token');
});

test('customer detail properly derives wallet entry directions and includes audit logs', function (): void {
    $admin = createCustomerDetailActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $wallet = WalletAccount::factory()->create([
        'user_id' => $customer->id,
        'balance_halalah' => 15000,
    ]);

    WalletEntry::factory()->create([
        'sequence' => 1,
        'wallet_account_id' => $wallet->id,
        'type' => WalletEntryType::Credit,
        'amount_halalah' => 5000,
        'balance_after_halalah' => 5000,
    ]);

    WalletEntry::factory()->create([
        'sequence' => 2,
        'wallet_account_id' => $wallet->id,
        'type' => WalletEntryType::Debit,
        'amount_halalah' => 2000,
        'balance_after_halalah' => 3000,
    ]);

    WalletEntry::factory()->create([
        'sequence' => 3,
        'wallet_account_id' => $wallet->id,
        'type' => WalletEntryType::Adjustment,
        'amount_halalah' => 12000,
        'balance_after_halalah' => 15000,
        'metadata' => ['direction' => 'credit', 'reason_code' => 'promotional_credit'],
    ]);

    app(RecordStaffAudit::class)->execute(
        $admin,
        $customer,
        new StaffAuditEvent('customers.suspended', [
            'reason_code' => 'abuse',
            'case_reference' => 'CASE-1234',
            'previous_active' => true,
            'new_active' => false,
        ], '127.0.0.1'),
    );

    $response = $this->actingAs($admin)->get("/admin/customers/{$customer->public_id}");
    $props = $response->original->getData()['page']['props'];

    expect($props['customer']['walletSummary']['balance']['amountMinor'])->toBe('15000')
        ->and($props['customer']['recentWalletEntries'])->toHaveCount(3)
        ->and($props['customer']['recentAuditLogs'])->toHaveCount(1)
        ->and($props['customer']['recentAuditLogs'][0]['action'])->toBe('customers.suspended')
        ->and($props['customer']['recentAuditLogs'][0]['metadata']['reason_code'])->toBe('abuse');
});

function createCustomerDetailFixture(): User
{
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'Fahad',
        'last_name' => 'Al-Dosari',
        'email' => 'fahad.dosari@example.test',
        'phone' => '+966509876543',
        'is_active' => true,
    ]);

    $wallet = WalletAccount::factory()->create([
        'user_id' => $customer->id,
        'balance_halalah' => 7500,
    ]);

    WalletEntry::factory()->create([
        'sequence' => 4,
        'wallet_account_id' => $wallet->id,
        'type' => WalletEntryType::Credit,
        'amount_halalah' => 7500,
        'balance_after_halalah' => 7500,
    ]);

    Order::factory()->for($customer)->create([
        'order_number' => 'AUT-CUST-ORD-1',
        'status' => OrderStatus::Completed,
        'total_halalah' => 7500,
        'placed_at' => now()->subDay(),
    ]);

    return $customer;
}

function createCustomerDetailActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINCUSTOMERDETAILSTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
