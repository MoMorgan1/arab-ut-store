<?php

use App\Actions\Checkout\ExpireAbandonedCheckouts;
use App\Checkout\DiscountEngine;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Str;

function abandonedCheckoutCoupon(): Coupon
{
    return Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'ONLYONE',
        'discount_type' => 'percent',
        'value' => 10,
        'minimum_order_halalah' => 0,
        'usage_limit' => 1,
        'is_active' => true,
    ]);
}

function pendingOrderFor(User $user, Coupon $coupon, int $ageHours): Order
{
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::PendingPayment,
        'paid_at' => null,
        'total_halalah' => 10_000,
    ]);

    $order->forceFill(['created_at' => now()->subHours($ageHours)])->save();

    CouponRedemption::query()->create([
        'public_id' => (string) Str::ulid(),
        'coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'order_id' => $order->id,
    ]);

    return $order;
}

test('an unpaid checkout past the grace period is cancelled, releasing the coupon use it reserved', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 1);

    $cancelled = app(ExpireAbandonedCheckouts::class)->execute();

    expect($cancelled)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->cancelled_at)->not->toBeNull();

    // The redemption row survives for audit; what changes is that it no longer
    // counts, because the engine excludes cancelled orders.
    expect(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1);

    // The point of the whole job: a usage_limit = 1 coupon that an abandoned
    // checkout was holding is usable again. Before the job this threw Limit.
    $applied = app(DiscountEngine::class)->evaluateSimpleCoupon($coupon->fresh(), 10_000, $user);

    expect($applied->discountHalalah)->toBe(1_000);
});

test('a checkout still inside the grace period is left alone', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS - 1);

    expect(app(ExpireAbandonedCheckouts::class)->execute())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

test('an order whose payment settled is never cancelled, however old it is', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 100);

    // The reconciler is behind, so the order still reads PendingPayment even
    // though the money arrived. Cancelling here would discard a real payment.
    Payment::query()->create([
        'public_id' => (string) Str::ulid(),
        'order_id' => $order->id,
        'provider' => 'paylink',
        'idempotency_key' => (string) Str::ulid(),
        'status' => PaymentStatus::Paid,
        'amount_halalah' => 10_000,
        'captured_halalah' => 10_000,
        'currency' => 'SAR',
    ]);

    expect(app(ExpireAbandonedCheckouts::class)->execute())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

test('an order carrying paid_at is never cancelled even without a payment row', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 5);
    $order->forceFill(['paid_at' => now()->subHour()])->save();

    expect(app(ExpireAbandonedCheckouts::class)->execute())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

test('a completed order is untouched', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Completed,
        'total_halalah' => 10_000,
    ]);
    $order->forceFill(['created_at' => now()->subHours(500)])->save();

    expect(app(ExpireAbandonedCheckouts::class)->execute())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Completed);
});
