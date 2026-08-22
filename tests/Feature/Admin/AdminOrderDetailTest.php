<?php

use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItemSecret;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests and nonprivileged accounts cannot open the Admin order detail', function (): void {
    $order = createDetailTestOrder();

    $this->get("/admin/orders/{$order->public_id}")->assertRedirect('/en/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get("/admin/orders/{$order->public_id}")->assertForbidden();
    }

    $inactiveStaff = createDetailTestActor(UserRole::Staff);
    $inactiveStaff->forceFill(['is_active' => false])->save();
    $this->actingAs($inactiveStaff)->get("/admin/orders/{$order->public_id}")->assertForbidden();
});

test('unconfirmed MFA privileged users are redirected to MFA setup', function (): void {
    $admin = createDetailTestActor(UserRole::Admin);
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();
    $order = createDetailTestOrder();

    $this->actingAs($admin)
        ->get("/admin/orders/{$order->public_id}")
        ->assertRedirect('/admin/settings');
});

test('confirmed privileged actors can open localized private order detail routes', function (
    UserRole $role,
    string $prefix,
): void {
    $actor = createDetailTestActor($role);
    $order = createDetailTestOrder();

    $this->actingAs($actor)
        ->get("{$prefix}/orders/{$order->public_id}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/orders/show', false)
            ->where('auth', null)
            ->where('locale', 'en')
            ->where('direction', 'ltr')
            ->where('order.id', (string) $order->public_id)
            ->where('order.orderNumber', $order->order_number)
            ->where('order.status', $order->status->value)
            ->has('order.items', 1)
            ->has('order.money.subtotal')
            ->has('order.money.total')
            ->has('allowedTransitions')
            ->has('transitionUrl')
        );
})->with([
    'admin default prefix' => [UserRole::Admin, '/admin'],
    'admin localized prefix' => [UserRole::Admin, '/en/admin'],
    'staff default prefix' => [UserRole::Staff, '/admin'],
    'staff localized prefix' => [UserRole::Staff, '/en/admin'],
]);

test('unknown order public id returns 404', function (): void {
    $admin = createDetailTestActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/orders/01K5UNKNOWN0000000000000000')
        ->assertNotFound();
});

test('order detail props never serialize credentials, provider metadata, or raw private fields', function (): void {
    $admin = createDetailTestActor(UserRole::Admin);
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'email' => 'customer-secret-leak-test@example.com',
        'password' => 'SuperSecretCustomerPassword!12',
    ]);

    $order = Order::factory()->for($customer)->create([
        'order_number' => 'AUT-PRIVACY-DETAIL-1',
        'status' => OrderStatus::Received,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'wallet_halalah' => 0,
        'payment_halalah' => 5000,
        'total_halalah' => 5000,
        'currency' => 'SAR',
        'placed_at' => now(),
    ]);

    $item = $order->items()->create([
        'sku' => 'AUT-SKU-PRIVACY',
        'name_ar' => 'خدمة كوينز',
        'name_en' => 'Coins Service',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);

    $secret = new OrderItemSecret([
        'order_item_id' => $item->id,
        'masked_summary' => ['account' => 'p***s'],
    ]);
    $secret->forceFill([
        'encrypted_payload' => ['credential' => 'order-detail-credential-must-never-appear'],
    ])->save();

    $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => 'PAYLINK-INTERNAL-PAYMENT-ID-SECRET',
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => 5000,
        'captured_halalah' => 5000,
        'refunded_halalah' => 0,
        'idempotency_key' => 'PAYMENT-IDEMPOTENCY-KEY-SECRET',
        'provider_metadata' => ['rawProviderWebhookPayload' => 'must-never-leak'],
        'paid_at' => now(),
    ]);

    $response = $this->actingAs($admin)->get("/admin/orders/{$order->public_id}");
    $content = $response->getContent();

    $response->assertOk();

    foreach ([
        'order-detail-credential-must-never-appear',
        'PAYLINK-INTERNAL-PAYMENT-ID-SECRET',
        'PAYMENT-IDEMPOTENCY-KEY-SECRET',
        'must-never-leak',
        'encrypted_payload',
        'provider_metadata',
        'provider_payment_id',
        'idempotency_key',
        'two_factor_secret',
        'two_factor_recovery_codes',
        (string) $customer->getRawOriginal('password'),
    ] as $forbiddenValue) {
        expect($content)->not->toContain($forbiddenValue);
    }
});

test('audit context is populated for Admin but null for Staff actors', function (): void {
    $admin = createDetailTestActor(UserRole::Admin);
    $staff = createDetailTestActor(UserRole::Staff);
    $order = createDetailTestOrder();

    app(RecordStaffAudit::class)->execute(
        actor: $admin,
        subject: $order,
        event: new StaffAuditEvent(
            action: 'orders.status_changed',
            metadata: [
                'source' => 'admin',
                'previous_status' => 'received',
                'new_status' => 'in_progress',
                'order_public_id' => (string) $order->public_id,
                'propagated_item_count' => 1,
            ],
            ipAddress: '127.0.0.1',
        ),
    );

    // Admin has audit.view permission -> receives auditContext array
    $this->actingAs($admin)
        ->get("/admin/orders/{$order->public_id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('order.auditContext', 1)
            ->where('order.auditContext.0.action', 'orders.status_changed')
            ->where('order.auditContext.0.actor.name', $admin->name)
        );

    // Staff lacks audit.view permission -> receives auditContext: null
    $this->actingAs($staff)
        ->get("/admin/orders/{$order->public_id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.auditContext', null)
        );
});

function createDetailTestActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINORDERDETAILSTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}

function createDetailTestOrder(): Order
{
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'Faisal',
        'last_name' => 'Al-Harbi',
        'email' => 'faisal@example.test',
        'phone' => '+966501234567',
    ]);

    $order = Order::factory()->for($customer)->create([
        'order_number' => 'AUT-DETAIL-'.Str::random(6),
        'status' => OrderStatus::Received,
        'subtotal_halalah' => 10000,
        'discount_halalah' => 1000,
        'wallet_halalah' => 2000,
        'payment_halalah' => 7000,
        'total_halalah' => 9000,
        'currency' => 'SAR',
        'placed_at' => now()->subDay(),
        'paid_at' => now()->subDay(),
    ]);

    $order->items()->create([
        'sku' => 'AUT-SKU-101',
        'name_ar' => 'خدمة فيفا',
        'name_en' => 'FC Service',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => 10000,
        'subtotal_halalah' => 10000,
        'discount_halalah' => 1000,
        'total_halalah' => 9000,
    ]);

    return $order;
}
