<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WalletEntryType;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function cancellableOrder(User $user, OrderStatus $status = OrderStatus::PendingPayment): Order
{
    $order = Order::factory()->for($user)->create([
        'status' => $status,
        'paid_at' => $status === OrderStatus::PendingPayment ? null : now(),
        'subtotal_halalah' => 10_000,
        'payment_halalah' => 10_000,
        'total_halalah' => 10_000,
    ]);

    OrderItem::factory()->for($order)->create([
        'status' => $status === OrderStatus::PendingPayment
            ? OrderItemStatus::PendingPayment
            : OrderItemStatus::Received,
    ]);

    return $order;
}

test('the owner can cancel an unpaid order and gets the wallet money back at once', function (): void {
    $user = User::factory()->create();
    $order = cancellableOrder($user);

    $writer = app(WalletLedgerWriter::class);
    $account = $writer->lockAccountFor($user->id);
    $writer->append($account, [
        'type' => WalletEntryType::Credit,
        'amount_halalah' => 2_000,
        'balance_delta_halalah' => 2_000,
        'order_id' => null,
        'refund_id' => null,
        'created_by_user_id' => null,
        'reference' => 'test-topup:'.$order->id,
        'metadata' => [],
    ]);
    $writer->append($account, [
        'type' => WalletEntryType::Debit,
        'amount_halalah' => 2_000,
        'balance_delta_halalah' => -2_000,
        'order_id' => $order->id,
        'refund_id' => null,
        'created_by_user_id' => null,
        'reference' => "order-wallet:{$order->id}",
        'metadata' => [],
    ]);
    $order->forceFill(['wallet_halalah' => 2_000])->save();

    $this->actingAs($user)
        ->from('/my-account/orders/'.$order->public_id)
        ->post('/my-account/orders/'.$order->public_id.'/cancel')
        ->assertRedirect('/my-account/orders/'.$order->public_id)
        ->assertSessionHas('status', 'order-cancelled');

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancelled_at)->not->toBeNull()
        ->and($order->items->first()->status)->toBe(OrderItemStatus::Cancelled)
        ->and((int) $account->fresh()->balance_halalah)->toBe(2_000);

    $history = OrderStatusHistory::query()
        ->where('order_id', $order->id)
        ->whereNull('order_item_id')
        ->latest('id')
        ->sole();

    expect($history->note_ar)->toBe(trans('orders.closed.customer_cancelled', [], 'ar'))
        ->and($history->note_en)->toBe(trans('orders.closed.customer_cancelled', [], 'en'))
        ->and($history->metadata['source'])->toBe('customer');
});

test('cancelling closes the Paylink invoice so the old payment link cannot charge the customer', function (): void {
    $user = User::factory()->create();
    $order = cancellableOrder($user);
    config()->set('services.paylink.environment', 'test');
    config()->set('services.paylink.api_id', 'merchant-id');
    config()->set('services.paylink.secret_key', 'merchant-secret');
    Cache::flush();
    $payment = Payment::query()->create([
        'public_id' => (string) Str::ulid(),
        'order_id' => $order->id,
        'provider' => 'paylink',
        'provider_payment_id' => 'INV-55555',
        'idempotency_key' => (string) Str::ulid(),
        'status' => PaymentStatus::Pending,
        'amount_halalah' => 10_000,
        'captured_halalah' => 0,
        'currency' => 'SAR',
    ]);
    Http::fake(fn ($request) => str_ends_with($request->url(), '/api/auth')
        ? Http::response(['id_token' => 'merchant-token'])
        : Http::response(['success' => true]));

    $this->actingAs($user)
        ->post('/en/my-account/orders/'.$order->public_id.'/cancel')
        ->assertRedirect();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Cancelled);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/cancelInvoice')
        && $request['transactionNo'] === 'INV-55555');
});

test('a paid order cannot be cancelled by the customer', function (): void {
    $user = User::factory()->create();
    $order = cancellableOrder($user, OrderStatus::Received);

    $this->actingAs($user)
        ->post('/my-account/orders/'.$order->public_id.'/cancel')
        ->assertStatus(409);

    expect($order->fresh()->status)->toBe(OrderStatus::Received);
});

test('another customer cannot cancel, or learn about, an order that is not theirs', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $order = cancellableOrder($owner);

    $this->actingAs($other)
        ->post('/my-account/orders/'.$order->public_id.'/cancel')
        ->assertNotFound();

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});
