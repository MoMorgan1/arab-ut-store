<?php

use App\Actions\Checkout\ExpireAbandonedCheckouts;
use App\Checkout\DiscountEngine;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\WalletEntryType;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletEntry;
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

    // A settled local payment row must block cancellation. The harder case -
    // the reconciler being behind, so the row still reads Pending while the
    // gateway already captured - is covered separately below.
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

test('an order with a gateway invoice is left alone even when no payment has settled locally', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 50);

    // The real danger: an invoice exists at Paylink, the customer paid it, and
    // the webhook was lost. Locally the payment still reads Pending. Cancelling
    // here charges the customer and delivers nothing, and a later reconcile
    // cannot undo it because it only transitions PendingPayment orders.
    Payment::query()->create([
        'public_id' => (string) Str::ulid(),
        'order_id' => $order->id,
        'provider' => 'paylink',
        'provider_payment_id' => 'INV-90210',
        'idempotency_key' => (string) Str::ulid(),
        'status' => PaymentStatus::Pending,
        'amount_halalah' => 10_000,
        'captured_halalah' => 0,
        'currency' => 'SAR',
    ]);

    expect(app(ExpireAbandonedCheckouts::class)->execute())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

test('cancelling a part-wallet checkout gives the customer their balance back', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 2);

    // PlaceOrder debits the wallet at placement even when a gateway payment is
    // still owed, so this order holds real customer money.
    $writer = app(WalletLedgerWriter::class);
    $account = $writer->lockAccountFor($user->id);
    $writer->append($account, [
        'type' => WalletEntryType::Credit,
        'amount_halalah' => 5_000,
        'balance_delta_halalah' => 5_000,
        'order_id' => null,
        'refund_id' => null,
        'created_by_user_id' => null,
        'reference' => 'test-topup:'.$order->id,
        'metadata' => [],
    ]);
    $writer->append($account, [
        'type' => WalletEntryType::Debit,
        'amount_halalah' => 5_000,
        'balance_delta_halalah' => -5_000,
        'order_id' => $order->id,
        'refund_id' => null,
        'created_by_user_id' => null,
        'reference' => "order-wallet:{$order->id}",
        'metadata' => [],
    ]);
    $order->forceFill(['wallet_halalah' => 5_000])->save();

    expect((int) $account->fresh()->balance_halalah)->toBe(0);

    expect(app(ExpireAbandonedCheckouts::class)->execute())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);

    // The whole point: expiring a checkout must never destroy wallet money.
    expect((int) $account->fresh()->balance_halalah)->toBe(5_000);
});

test('re-running the job does not credit the wallet twice', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 2);

    $writer = app(WalletLedgerWriter::class);
    $account = $writer->lockAccountFor($user->id);
    $writer->append($account, [
        'type' => WalletEntryType::Credit,
        'amount_halalah' => 5_000,
        'balance_delta_halalah' => 5_000,
        'order_id' => null,
        'refund_id' => null,
        'created_by_user_id' => null,
        'reference' => 'test-topup:'.$order->id,
        'metadata' => [],
    ]);
    $order->forceFill(['wallet_halalah' => 5_000])->save();

    app(ExpireAbandonedCheckouts::class)->execute();
    app(ExpireAbandonedCheckouts::class)->execute();

    expect(WalletEntry::query()->where('reference', "order-wallet-released:{$order->id}")->count())->toBe(1);
});

test('cancelling an expired checkout cancels its items too', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 2);

    $order->items()->create([
        'public_id' => (string) Str::ulid(),
        'sku' => 'TEST-SKU',
        'name_ar' => 'عنصر',
        'name_en' => 'Item',
        'quantity' => 1,
        'unit_price_halalah' => 10_000,
        'subtotal_halalah' => 10_000,
        'total_halalah' => 10_000,
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'market' => 'console',
        'status' => OrderItemStatus::PendingPayment->value,
    ]);

    app(ExpireAbandonedCheckouts::class)->execute();

    expect($order->fresh()->items->first()->status)->toBe(OrderItemStatus::Cancelled);
});
