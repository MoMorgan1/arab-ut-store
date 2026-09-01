<?php

use App\Actions\Checkout\RefundPaylinkOrder;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Payments\PaymentConfigurationException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Http\Client\Request;
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

/** @return array{admin: User, order: Order, payment: Payment} */
function refundablePaylinkOrder(): array
{
    $admin = mfaConfirmedAdmin();
    $order = Order::factory()->create([
        'order_number' => 'AUT-REFUND-1001',
        'status' => OrderStatus::Received,
        'currency' => 'SAR',
        'subtotal_halalah' => 1250,
        'payment_halalah' => 1250,
        'total_halalah' => 1250,
        'paid_at' => now(),
    ]);
    $order->items()->create([
        'product_variant_id' => null,
        'name_ar' => 'خدمة رقمية',
        'name_en' => 'Digital service',
        'sku' => 'SBC_REFUND_TEST',
        'service_type' => 'sbc',
        'platform' => 'playstation',
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => 1250,
        'subtotal_halalah' => 1250,
        'discount_halalah' => 0,
        'total_halalah' => 1250,
        'configuration' => [],
    ]);
    $payment = $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => '1710000000099',
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => 1250,
        'captured_halalah' => 1250,
        'refunded_halalah' => 0,
        'idempotency_key' => 'paylink-refund-fixture',
        'paid_at' => now(),
    ]);

    return compact('admin', 'order', 'payment');
}

function mfaConfirmedAdmin(): User
{
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINTESTTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $admin;
}

test('an admin can issue one verified full Paylink refund and exact retries never call the provider twice', function () {
    ['admin' => $admin, 'order' => $order] = refundablePaylinkOrder();
    Http::fake([
        'https://restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'partner-token']),
        'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund' => Http::response([
            'id' => 237,
            'orderNumber' => 'AUT-REFUND-1001',
            'amount' => 12.50,
            'currency' => 'SAR',
            'refundReason' => 'Customer request.',
            'createDatetime' => 1716194603030,
        ]),
    ]);

    $first = app(RefundPaylinkOrder::class)->execute($order, 'Customer request.', $admin);
    $replayed = app(RefundPaylinkOrder::class)->execute($order->fresh(), 'Customer request.', $admin);

    expect($first->is($replayed))->toBeTrue()
        ->and($first->status)->toBe('completed')
        ->and($first->provider_refund_id)->toBe('237')
        ->and($first->amount_halalah)->toBe(1250)
        ->and($first->provider_metadata)->toMatchArray(['currency' => 'SAR'])
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->items()->sole()->status)->toBe(OrderItemStatus::Refunded)
        ->and($order->payments()->sole()->status)->toBe(PaymentStatus::Refunded)
        ->and($order->payments()->sole()->refunded_halalah)->toBe(1250);

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/refund')))
        ->toHaveCount(1);

    $audits = StaffAuditLog::query()
        ->where('auditable_type', $order->getMorphClass())
        ->where('auditable_id', $order->id)
        ->get();

    expect($audits)->toHaveCount(1)
        ->and($audits->first()->action)->toBe('refunds.requested')
        ->and($audits->first()->actor_user_id)->toBe($admin->id)
        ->and($audits->first()->metadata)->toBe([
            'amount_halalah' => 1250,
            'currency' => 'SAR',
            'provider' => 'paylink',
            'refund_public_id' => $first->public_id,
        ]);
});

test('refunds fail closed for non admin actors and unpaid orders', function () {
    ['order' => $order] = refundablePaylinkOrder();
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $staff = User::factory()->create(['role' => UserRole::Staff]);

    expect(fn () => app(RefundPaylinkOrder::class)->execute($order, 'Customer request.', $customer))
        ->toThrow(CheckoutUnavailable::class)
        ->and(fn () => app(RefundPaylinkOrder::class)->execute($order, 'Customer request.', $staff))
        ->toThrow(CheckoutUnavailable::class);

    expect(StaffAuditLog::query()->count())->toBe(0);

    $order->payments()->sole()->update(['status' => PaymentStatus::Pending]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    expect(fn () => app(RefundPaylinkOrder::class)->execute($order->fresh(), 'Customer request.', $admin))
        ->toThrow(CheckoutUnavailable::class);

    $failedAudit = StaffAuditLog::query()
        ->where('action', 'refunds.failed')
        ->sole();

    expect($failedAudit->metadata)->toBe([
        'amount_halalah' => 1250,
        'currency' => 'SAR',
        'provider' => 'paylink',
        'failure_code' => 'manual_review_required',
    ]);
});

test('an existing refund for the selected payment requires manual review even with a noncanonical key', function () {
    ['admin' => $admin, 'order' => $order, 'payment' => $payment] = refundablePaylinkOrder();
    $existing = Refund::query()->create([
        'public_id' => (string) Str::ulid(),
        'order_id' => $order->id,
        'payment_id' => $payment->id,
        'created_by_user_id' => $admin->id,
        'method' => 'paylink',
        'status' => 'failed',
        'amount_halalah' => 1250,
        'idempotency_key' => 'legacy-refund-key',
    ]);
    Http::fake();

    expect(fn () => app(RefundPaylinkOrder::class)->execute($order, 'Customer request.', $admin))
        ->toThrow(CheckoutUnavailable::class, 'manual review');

    Http::assertNothingSent();
    expect(StaffAuditLog::query()->where('action', 'refunds.failed')->sole()->metadata)
        ->toBe([
            'amount_halalah' => 1250,
            'currency' => 'SAR',
            'provider' => 'paylink',
            'failure_code' => 'manual_review_required',
            'refund_public_id' => $existing->public_id,
        ]);
});

test('missing Partner credentials fail before a provider call and do not poison a later refund', function () {
    ['admin' => $admin, 'order' => $order] = refundablePaylinkOrder();
    config()->set('services.paylink.partner_api_key', null);
    Http::fake();

    expect(fn () => app(RefundPaylinkOrder::class)->execute($order, 'Customer request.', $admin))
        ->toThrow(PaymentConfigurationException::class);

    expect($order->refunds()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Received)
        ->and($order->payments()->sole()->status)->toBe(PaymentStatus::Paid);
    Http::assertNothingSent();

    $failedAudit = StaffAuditLog::query()
        ->where('action', 'refunds.failed')
        ->sole();

    expect($failedAudit->metadata)->toBe([
        'amount_halalah' => 1250,
        'currency' => 'SAR',
        'provider' => 'paylink',
        'failure_code' => 'provider_unavailable',
    ]);
});

test('a mismatched Paylink refund is quarantined for manual review without changing the order', function () {
    ['admin' => $admin, 'order' => $order] = refundablePaylinkOrder();
    Http::fake([
        'https://restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'partner-token']),
        'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund' => Http::response([
            'id' => 238,
            'orderNumber' => 'AUT-REFUND-1001',
            'amount' => 11.00,
            'currency' => 'SAR',
            'refundReason' => 'Customer request.',
            'createDatetime' => 1716194603030,
        ]),
    ]);

    expect(fn () => app(RefundPaylinkOrder::class)->execute($order, 'Customer request.', $admin))
        ->toThrow(CheckoutUnavailable::class, 'mismatched refund');

    $failedRefund = $order->refunds()->sole();

    expect($failedRefund->status)->toBe('failed')
        ->and($order->fresh()->status)->toBe(OrderStatus::Received)
        ->and($order->payments()->sole()->status)->toBe(PaymentStatus::Paid);

    $failedAudit = StaffAuditLog::query()
        ->where('action', 'refunds.failed')
        ->sole();

    expect($failedAudit->metadata)->toBe([
        'amount_halalah' => 1250,
        'currency' => 'SAR',
        'provider' => 'paylink',
        'failure_code' => 'provider_mismatch',
        'refund_public_id' => $failedRefund->public_id,
    ]);
});

test('the admin refund endpoint is authenticated, admin restricted, mfa gated, password confirmed, full amount only and no store', function () {
    ['admin' => $admin, 'order' => $order] = refundablePaylinkOrder();
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $staff = User::factory()->create(['role' => UserRole::Staff]);
    $staff->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('STAFFTESTTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $unconfirmedMfaAdmin = User::factory()->create(['role' => UserRole::Admin]);
    Http::fake();

    $url = '/admin/api/orders/'.$order->public_id.'/refund';
    $payload = ['amountHalalah' => 1250, 'reason' => 'Customer request.'];

    $this->postJson($url, $payload)->assertUnauthorized();
    $this->actingAs($customer)->postJson($url, $payload)->assertForbidden();
    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson($url, $payload)
        ->assertForbidden();
    $this->actingAs($unconfirmedMfaAdmin)->postJson($url, $payload)
        ->assertRedirect('/admin/settings');
    $this->actingAs($admin)->postJson($url, [...$payload, 'amountHalalah' => 1249])
        ->assertUnprocessable()
        ->assertHeader('Cache-Control', 'no-store, private');

    Http::assertNothingSent();
});

test('the admin refund endpoint completes one provider verified refund', function () {
    ['admin' => $admin, 'order' => $order] = refundablePaylinkOrder();
    Http::fake([
        'https://restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'partner-token']),
        'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund' => Http::response([
            'id' => 239,
            'orderNumber' => 'AUT-REFUND-1001',
            'amount' => 12.50,
            'currency' => 'SAR',
            'refundReason' => 'Customer request.',
            'createDatetime' => 1716194603030,
        ]),
    ]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/orders/'.$order->public_id.'/refund', [
            'amountHalalah' => 1250,
            'reason' => 'Customer request.',
        ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.amountHalalah', 1250)
        ->assertJsonMissing(['providerRefundId' => '239']);

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

test('a cancelled order with a captured payment can be refunded', function () {
    ['admin' => $admin, 'order' => $order] = refundablePaylinkOrder();
    $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

    Http::fake([
        'https://restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'partner-token']),
        'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund' => Http::response([
            'id' => 240,
            'orderNumber' => 'AUT-REFUND-1001',
            'amount' => 12.50,
            'currency' => 'SAR',
            'refundReason' => 'Refund cancelled order.',
            'createDatetime' => 1716194603030,
        ]),
    ]);

    $refund = app(RefundPaylinkOrder::class)->execute($order, 'Refund cancelled order.', $admin);

    expect($refund->status)->toBe('completed')
        ->and($refund->provider_refund_id)->toBe('240')
        ->and($refund->amount_halalah)->toBe(1250)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->items()->sole()->status)->toBe(OrderItemStatus::Refunded)
        ->and($order->payments()->sole()->status)->toBe(PaymentStatus::Refunded)
        ->and($order->payments()->sole()->refunded_halalah)->toBe(1250);
});
