<?php

use App\Actions\Checkout\ExpireAbandonedCheckouts;
use App\Checkout\DiscountEngine;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\WalletEntryType;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        'payment_halalah' => 10_000,
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

/**
 * A Paylink invoice the order got as far as raising, with the gateway
 * answering getInvoice with the given status. Wraps the order number and
 * amount the reconciler checks, so a "Paid" answer is accepted.
 */
function gatewayInvoiceFor(Order $order, string $status, bool $cancelSucceeds = true, bool $gatewayDown = false): Payment
{
    config()->set('services.paylink.environment', 'test');
    config()->set('services.paylink.api_id', 'merchant-id');
    config()->set('services.paylink.secret_key', 'merchant-secret');
    Cache::flush();

    $payment = Payment::query()->create([
        'public_id' => (string) Str::ulid(),
        'order_id' => $order->id,
        'provider' => 'paylink',
        'provider_payment_id' => 'INV-90210',
        'idempotency_key' => (string) Str::ulid(),
        'status' => PaymentStatus::Pending,
        'amount_halalah' => (int) $order->payment_halalah,
        'captured_halalah' => 0,
        'currency' => 'SAR',
    ]);

    Http::fake(function ($request) use ($order, $status, $cancelSucceeds, $gatewayDown) {
        if ($gatewayDown) {
            return Http::response(['error' => 'down'], 503);
        }

        if (str_ends_with($request->url(), '/api/auth')) {
            return Http::response(['id_token' => 'merchant-token']);
        }

        if (str_ends_with($request->url(), '/api/cancelInvoice')) {
            return Http::response(['success' => $cancelSucceeds]);
        }

        return Http::response([
            'success' => true,
            'transactionNo' => 'INV-90210',
            'orderStatus' => $status,
            'amount' => $order->payment_halalah / 100,
            'url' => strtolower($status) === 'pending' ? 'https://payment.paylink.sa/pay/info/INV-90210' : null,
            'gatewayOrderRequest' => ['orderNumber' => $order->order_number, 'currency' => 'SAR'],
            'paymentReceipt' => strtolower($status) === 'paid' ? ['paymentMethod' => 'mada'] : null,
        ]);
    });

    return $payment;
}

test('an order whose Paylink invoice is still unpaid past the grace period is cancelled here and at the gateway', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 1);
    $payment = gatewayInvoiceFor($order, 'Pending');

    expect(app(ExpireAbandonedCheckouts::class)->execute())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Cancelled);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/cancelInvoice')
        && $request['transactionNo'] === 'INV-90210');
});

test('an order the customer paid at Paylink while the callback was lost is marked paid, never cancelled', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 50);
    $payment = gatewayInvoiceFor($order, 'Paid');

    expect(app(ExpireAbandonedCheckouts::class)->execute())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Received)
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/cancelInvoice'));
});

test('an order Paylink cannot be asked about is left alone for the next run', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 50);
    gatewayInvoiceFor($order, 'Pending', gatewayDown: true);

    expect(app(ExpireAbandonedCheckouts::class)->execute())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

test('a gateway that refuses to close the invoice does not undo the local cancellation', function (): void {
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 1);
    gatewayInvoiceFor($order, 'Pending', cancelSucceeds: false);

    expect(app(ExpireAbandonedCheckouts::class)->execute())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
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

test('an expired checkout records why it was cancelled, for the audit and for the customer', function (): void {
    // This job used to change the status and write no history at all, so the
    // order simply became "cancelled" with nothing behind it: no trail for
    // staff, and nothing for the order page to tell the customer.
    $user = User::factory()->create();
    $coupon = abandonedCheckoutCoupon();
    $order = pendingOrderFor($user, $coupon, ExpireAbandonedCheckouts::GRACE_HOURS + 2);

    app(ExpireAbandonedCheckouts::class)->execute();

    $history = OrderStatusHistory::query()
        ->where('order_id', $order->id)
        ->whereNull('order_item_id')
        ->sole();

    expect($history->status)->toBe(OrderStatusHistoryStatus::Cancelled)
        ->and($history->metadata['source'])->toBe('checkout_expiry')
        ->and($history->note_ar)->not->toBeNull()
        ->and($history->note_en)->toContain('not charged')
        ->and($history->note_ar)->not->toBe($history->note_en);
});
