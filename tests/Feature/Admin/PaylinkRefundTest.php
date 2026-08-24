<?php

use App\Admin\Presenters\AdminOrderDetailPage;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

beforeEach(function (): void {
    config()->set('services.paylink', [
        'environment' => 'test',
        'api_id' => 'merchant-id',
        'secret_key' => 'merchant-secret',
        'webhook_token' => 'webhook-secret',
        'partner_profile_no' => 'profile-no',
        'partner_api_key' => 'partner-api-key',
        'merchant_lookup_key' => 'accountNo',
        'merchant_lookup_value' => '123456',
    ]);
    Cache::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{admin: User, order: Order, payment: Payment} */
function createRefundablePaylinkOrderFixture(int $amountHalalah = 2500): array
{
    $admin = createPaylinkAdminActor();
    $order = Order::factory()->create([
        'order_number' => 'AUT-PAYLINK-REFUND-1',
        'status' => OrderStatus::Received,
        'currency' => 'SAR',
        'subtotal_halalah' => $amountHalalah,
        'discount_halalah' => 0,
        'wallet_halalah' => 0,
        'payment_halalah' => $amountHalalah,
        'total_halalah' => $amountHalalah,
        'paid_at' => now(),
    ]);

    $order->items()->create([
        'product_variant_id' => null,
        'name_ar' => 'خدمة رقمية',
        'name_en' => 'Digital service',
        'sku' => 'AUT-SKU-PAYLINK',
        'service_type' => 'coins',
        'platform' => 'playstation',
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => $amountHalalah,
        'subtotal_halalah' => $amountHalalah,
        'discount_halalah' => 0,
        'total_halalah' => $amountHalalah,
        'configuration' => [],
    ]);

    $payment = $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => '1710000000100',
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => $amountHalalah,
        'captured_halalah' => $amountHalalah,
        'refunded_halalah' => 0,
        'idempotency_key' => 'paylink-payment-fixture-'.$order->id,
        'paid_at' => now(),
    ]);

    return compact('admin', 'order', 'payment');
}

function createPaylinkAdminActor(UserRole $role = UserRole::Admin): User
{
    $actor = User::factory()->create(['role' => $role]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINPAYLINKREFUNDTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}

test('unauthenticated users and non-admin actors are forbidden from refund endpoint', function (): void {
    ['order' => $order] = createRefundablePaylinkOrderFixture();
    $url = "/admin/api/orders/{$order->public_id}/refund";
    $payload = ['amountHalalah' => 2500, 'reason' => 'Customer cancellation.'];

    $this->postJson($url, $payload)->assertUnauthorized();

    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($customer)->postJson($url, $payload)->assertForbidden();

    $staff = createPaylinkAdminActor(UserRole::Staff);
    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, $payload)
        ->assertForbidden();

    expect(StaffAuditLog::query()->count())->toBe(0);
});

test('unconfirmed MFA redirects to security setup', function (): void {
    ['admin' => $admin, 'order' => $order] = createRefundablePaylinkOrderFixture();
    $url = "/admin/api/orders/{$order->public_id}/refund";
    $payload = ['amountHalalah' => 2500, 'reason' => 'Customer request.'];

    $unconfirmedAdmin = createPaylinkAdminActor();
    $unconfirmedAdmin->forceFill(['two_factor_confirmed_at' => null])->save();
    $this->actingAs($unconfirmedAdmin)->postJson($url, $payload)
        ->assertRedirect('/admin/settings');

    // The password re-prompt is gone from the admin by owner decision; the MFA
    // gate above is what still stands between a session and a refund.
    expect(StaffAuditLog::query()->count())->toBe(0);
});

test('both default and localized route families execute refund and return safe JSON headers', function (string $prefix): void {
    ['admin' => $admin, 'order' => $order] = createRefundablePaylinkOrderFixture(3000);
    Http::fake([
        'https://restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'partner-token']),
        'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund' => Http::response([
            'id' => 999,
            'orderNumber' => 'AUT-PAYLINK-REFUND-1',
            'amount' => 30.00,
            'currency' => 'SAR',
            'refundReason' => 'Staff processed refund.',
            'createDatetime' => 1716194603030,
        ]),
    ]);

    $url = "{$prefix}/api/orders/{$order->public_id}/refund";
    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, [
            'amountHalalah' => 3000,
            'reason' => 'Staff processed refund.',
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.amountHalalah', 3000)
        ->assertJsonMissing(['providerRefundId' => '999']);

    $audit = StaffAuditLog::query()->where('action', 'refunds.requested')->sole();
    expect($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->auditable_type)->toBe($order->getMorphClass())
        ->and($audit->auditable_id)->toBe($order->id)
        ->and($audit->metadata)->toBe([
            'amount_halalah' => 3000,
            'currency' => 'SAR',
            'provider' => 'paylink',
            'refund_public_id' => $response->json('data.refundId'),
        ]);
})->with([
    'admin default prefix' => ['/admin'],
    'admin localized prefix' => ['/en/admin'],
]);

test('amount mismatch records refunds.rejected audit and returns 422 full_refund_required', function (): void {
    ['admin' => $admin, 'order' => $order] = createRefundablePaylinkOrderFixture(2500);
    $url = "/admin/api/orders/{$order->public_id}/refund";

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, [
            'amountHalalah' => 2000, // mismatched amount
            'reason' => 'Partial refund attempt.',
        ]);

    $response->assertStatus(422)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('error.code', 'full_refund_required');

    $audit = StaffAuditLog::query()->where('action', 'refunds.rejected')->sole();
    expect($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->auditable_id)->toBe($order->id)
        ->and($audit->metadata)->toBe([
            'amount_halalah' => 2000,
            'currency' => 'SAR',
            'provider' => 'paylink',
            'failure_code' => 'full_refund_required',
        ]);
});

test('request validation failures are not audited', function (): void {
    ['admin' => $admin, 'order' => $order] = createRefundablePaylinkOrderFixture(2500);
    $url = "/admin/api/orders/{$order->public_id}/refund";

    // Unknown fields
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, [
            'amountHalalah' => 2500,
            'reason' => 'Reason',
            'unknown_field' => 'bad',
        ])
        ->assertUnprocessable();

    // Empty reason
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, [
            'amountHalalah' => 2500,
            'reason' => '',
        ])
        ->assertUnprocessable();

    // Reason > 500 characters
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, [
            'amountHalalah' => 2500,
            'reason' => str_repeat('a', 501),
        ])
        ->assertUnprocessable();

    expect(StaffAuditLog::query()->count())->toBe(0);
});

test('replay of completed refund returns 200 without creating a second audit', function (): void {
    ['admin' => $admin, 'order' => $order] = createRefundablePaylinkOrderFixture(2500);
    Http::fake([
        'https://restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'partner-token']),
        'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund' => Http::response([
            'id' => 777,
            'orderNumber' => 'AUT-PAYLINK-REFUND-1',
            'amount' => 25.00,
            'currency' => 'SAR',
            'refundReason' => 'Customer request.',
            'createDatetime' => 1716194603030,
        ]),
    ]);

    $url = "/admin/api/orders/{$order->public_id}/refund";
    $payload = ['amountHalalah' => 2500, 'reason' => 'Customer request.'];

    $firstResponse = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, $payload)
        ->assertOk();

    $replayResponse = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, $payload)
        ->assertOk();

    expect($firstResponse->json('data.refundId'))->toBe($replayResponse->json('data.refundId'));

    expect(StaffAuditLog::query()->where('action', 'refunds.requested')->count())->toBe(1);
});

test('rate limiter throttles after ten requests for the same admin, returns 429 with Retry-After, and adds no audit', function (): void {
    ['admin' => $admin, 'order' => $order] = createRefundablePaylinkOrderFixture(2500);
    Http::fake();
    $url = "/admin/api/orders/{$order->public_id}/refund";

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson($url, [
                'amountHalalah' => 2500,
                'reason' => '',
            ])
            ->assertUnprocessable();
    }

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, [
            'amountHalalah' => 2500,
            'reason' => 'Valid reason',
        ]);

    $response->assertStatus(429)
        ->assertHeader('Retry-After');

    Http::assertNothingSent();
    expect(StaffAuditLog::query()->count())->toBe(0);
});

test('order detail presenter exposes the captured amount and route for an eligible refund', function (): void {
    ['admin' => $admin, 'order' => $order] = createRefundablePaylinkOrderFixture(2500);

    $props = app(AdminOrderDetailPage::class)->for($admin, 'en', $order, null);

    expect($props['refund'])->toBe([
        'eligible' => true,
        'amountMinor' => '2500',
        'currency' => 'SAR',
    ])->and($props['refundUrl'])->toBe("/admin/api/orders/{$order->public_id}/refund");
});

test('order detail presenter rejects non-refundable order and payment states', function (
    array $orderChanges,
    array $paymentChanges,
    string $expectedAmount,
): void {
    ['admin' => $admin, 'order' => $order, 'payment' => $payment] = createRefundablePaylinkOrderFixture(2500);
    $order->update($orderChanges);
    $payment->update($paymentChanges);

    $props = app(AdminOrderDetailPage::class)->for($admin, 'en', $order->fresh(), null);

    expect($props['refund'])->toBe([
        'eligible' => false,
        'amountMinor' => $expectedAmount,
        'currency' => 'SAR',
    ]);
})->with([
    'cancelled order' => [['status' => OrderStatus::Cancelled], [], '2500'],
    'pending payment' => [[], ['status' => PaymentStatus::Pending], '2500'],
    'captured amount mismatch' => [[], ['captured_halalah' => 2000], '2000'],
    'already refunded payment' => [[], ['refunded_halalah' => 2500], '2500'],
]);

test('order detail presenter blocks any existing refund for the selected payment', function (): void {
    ['admin' => $admin, 'order' => $order, 'payment' => $payment] = createRefundablePaylinkOrderFixture(2500);
    Refund::query()->create([
        'public_id' => (string) Str::ulid(),
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'created_by_user_id' => $admin->id,
        'method' => 'paylink',
        'status' => 'failed',
        'amount_halalah' => 2500,
        'idempotency_key' => 'legacy-refund-key',
    ]);

    $props = app(AdminOrderDetailPage::class)->for($admin, 'en', $order->fresh(), null);

    expect($props['refund']['eligible'])->toBeFalse();
});

test('order detail presenter uses a safe zero amount when no Paylink payment exists', function (): void {
    ['admin' => $admin, 'order' => $order, 'payment' => $payment] = createRefundablePaylinkOrderFixture(2500);
    $payment->update(['provider' => 'tamara']);

    $props = app(AdminOrderDetailPage::class)->for($admin, 'en', $order->fresh(), null);

    expect($props['refund'])->toBe([
        'eligible' => false,
        'amountMinor' => '0',
        'currency' => 'SAR',
    ]);
});
