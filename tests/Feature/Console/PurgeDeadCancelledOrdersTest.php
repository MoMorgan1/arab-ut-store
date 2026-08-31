<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\WalletEntryType;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\ExternalRef;
use App\Models\FulfillmentAttachment;
use App\Models\FulfillmentJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\SecretAccessLog;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A cancelled order past the default grace period, with the whole chain
 * hanging off it: an item, an EA secret, an access log, status history, a
 * failed payment, a coupon redemption, a Salla external ref, a squad image
 * attachment and a fulfillment job.
 *
 * @return array{customer: User, order: Order, item: OrderItem, secret: OrderItemSecret, squad_path: string}
 */
function purgeTestOrder(array $overrides = []): array
{
    $customer = User::factory()->create();

    $order = Order::factory()->for($customer)->create(array_merge([
        'status' => OrderStatus::Cancelled,
        'cancelled_at' => now()->subHours(30),
        'placed_at' => now()->subHours(31),
        'paid_at' => null,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'wallet_halalah' => 0,
        'payment_halalah' => 5000,
        'total_halalah' => 5000,
    ], $overrides));

    $item = $order->items()->create([
        'sku' => 'PURGE_'.fake()->unique()->numerify('########'),
        'name_ar' => 'طلب عنصر تجريبي',
        'name_en' => 'Purge test order item',
        'service_type' => ServiceType::FutChampions,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::Cancelled,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
        'configuration' => [],
    ]);

    $secret = new OrderItemSecret([
        'order_item_id' => $item->id,
        'masked_summary' => ['account' => 'p***r@example.com'],
    ]);
    $secret->forceFill([
        'encrypted_payload' => [
            'ea_email' => 'player@example.com',
            'ea_password' => 'SecretPassword123!',
        ],
    ])->save();

    SecretAccessLog::create([
        'order_item_secret_id' => $secret->id,
        'purpose' => 'fulfillment',
    ]);

    $order->statusHistory()->create([
        'status' => OrderStatusHistoryStatus::Cancelled,
        'note_ar' => 'أُلغي لعدم السداد.',
        'note_en' => 'Cancelled as unpaid.',
        'metadata' => ['source' => 'checkout_expiry'],
    ]);

    $order->payments()->create([
        'provider' => 'paylink',
        'status' => PaymentStatus::Failed,
        'amount_halalah' => 5000,
        'captured_halalah' => 0,
        'refunded_halalah' => 0,
        'idempotency_key' => 'purge-test:'.Str::ulid(),
    ]);

    $coupon = Coupon::create([
        'code' => 'PURGE'.Str::ulid(),
        'discount_type' => 'fixed',
        'value' => 500,
    ]);

    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'user_id' => $customer->id,
        'order_id' => $order->id,
    ]);

    ExternalRef::create([
        'source' => 'salla',
        'entity' => 'order',
        'external_id' => $order->order_number,
        'internal_id' => $order->id,
    ]);

    FulfillmentJob::factory()->for($item)->create();

    $squadPath = 'purge-tests/'.Str::ulid().'/squad.png';
    Storage::disk('local')->put($squadPath, 'fake-image-bytes');

    FulfillmentAttachment::create([
        'order_item_id' => $item->id,
        'kind' => 'squad_image',
        'disk' => 'local',
        'path' => $squadPath,
        'mime_type' => 'image/png',
        'bytes' => 17,
        'sha256' => hash('sha256', 'fake-image-bytes'),
    ]);

    return ['customer' => $customer, 'order' => $order, 'item' => $item, 'secret' => $secret, 'squad_path' => $squadPath];
}

beforeEach(function (): void {
    Storage::fake('local');
});

test('a cancelled order that never captured money is purged with everything hanging off it', function (): void {
    Log::spy();

    ['customer' => $customer, 'order' => $order, 'item' => $item, 'secret' => $secret, 'squad_path' => $squadPath] = purgeTestOrder();

    $this->artisan('orders:purge-cancelled')
        ->expectsOutputToContain('Purged 1 dead cancelled order(s).')
        ->assertSuccessful();

    // Every table in the chain is actually empty for this order.
    expect(Order::find($order->id))->toBeNull()
        ->and(OrderItem::find($item->id))->toBeNull()
        ->and(OrderItemSecret::find($secret->id))->toBeNull()
        ->and(SecretAccessLog::where('order_item_secret_id', $secret->id)->count())->toBe(0)
        ->and(OrderStatusHistory::where('order_id', $order->id)->count())->toBe(0)
        ->and(Payment::where('order_id', $order->id)->count())->toBe(0)
        ->and(FulfillmentJob::where('order_item_id', $item->id)->count())->toBe(0)
        ->and(CouponRedemption::where('order_id', $order->id)->count())->toBe(0)
        ->and(ExternalRef::where('entity', 'order')->where('internal_id', $order->id)->count())->toBe(0)
        ->and(FulfillmentAttachment::where('order_item_id', $item->id)->count())->toBe(0)
        ->and(Storage::disk('local')->exists($squadPath))->toBeFalse()
        // The customer the order hung off is untouched.
        ->and(User::find($customer->id))->not->toBeNull();

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Cancelled-order purge summary')
            && $context['deleted'] === 1
            && $context['skipped_money'] === []);
});

test('a cancelled order with a captured payment is never deleted', function (): void {
    ['order' => $order, 'item' => $item, 'secret' => $secret] = purgeTestOrder();

    $order->payments()->create([
        'provider' => 'paylink',
        'status' => PaymentStatus::Paid,
        'amount_halalah' => 5000,
        'captured_halalah' => 5000,
        'refunded_halalah' => 0,
        'idempotency_key' => 'purge-captured:'.Str::ulid(),
    ]);

    $this->artisan('orders:purge-cancelled')
        ->expectsOutputToContain('Skipped 1 cancelled order(s) with money captured or refunded')
        ->assertSuccessful();

    expect(Order::find($order->id))->not->toBeNull()
        ->and(OrderItem::find($item->id))->not->toBeNull()
        ->and(OrderItemSecret::find($secret->id))->not->toBeNull()
        ->and(SecretAccessLog::where('order_item_secret_id', $secret->id)->count())->toBe(1)
        ->and(Payment::where('order_id', $order->id)->count())->toBe(2);
});

test('a cancelled order whose payment was refunded is never deleted, even with nothing still captured', function (): void {
    ['order' => $order] = purgeTestOrder();

    $order->payments()->create([
        'provider' => 'paylink',
        'status' => PaymentStatus::Refunded,
        'amount_halalah' => 5000,
        'captured_halalah' => 0,
        'refunded_halalah' => 3000,
        'idempotency_key' => 'purge-refunded:'.Str::ulid(),
    ]);

    $this->artisan('orders:purge-cancelled')->assertSuccessful();

    expect(Order::find($order->id))->not->toBeNull()
        ->and(Payment::where('order_id', $order->id)->count())->toBe(2);
});

test('a cancelled order still inside the grace period is not deleted', function (): void {
    ['order' => $order, 'item' => $item, 'secret' => $secret] = purgeTestOrder([
        'cancelled_at' => now()->subHour(),
    ]);

    $this->artisan('orders:purge-cancelled')->assertSuccessful();

    expect(Order::find($order->id))->not->toBeNull()
        ->and(OrderItem::find($item->id))->not->toBeNull()
        ->and(OrderItemSecret::find($secret->id))->not->toBeNull()
        ->and(SecretAccessLog::where('order_item_secret_id', $secret->id)->count())->toBe(1);
});

test('a non-cancelled order is never touched, whatever its payment state', function (): void {
    $completed = purgeTestOrder([
        'status' => OrderStatus::Completed,
        'cancelled_at' => null,
        'paid_at' => now()->subHours(30),
        'completed_at' => now()->subHours(29),
    ]);
    $completed['order']->payments()->create([
        'provider' => 'paylink',
        'status' => PaymentStatus::Paid,
        'amount_halalah' => 5000,
        'captured_halalah' => 5000,
        'refunded_halalah' => 0,
        'idempotency_key' => 'purge-completed:'.Str::ulid(),
    ]);

    $pending = purgeTestOrder([
        'status' => OrderStatus::PendingPayment,
        'cancelled_at' => null,
    ]);

    $this->artisan('orders:purge-cancelled')->assertSuccessful();

    expect(Order::find($completed['order']->id))->not->toBeNull()
        ->and(Order::find($pending['order']->id))->not->toBeNull()
        ->and(SecretAccessLog::where('order_item_secret_id', $completed['secret']->id)->count())->toBe(1)
        ->and(SecretAccessLog::where('order_item_secret_id', $pending['secret']->id)->count())->toBe(1);
});

test('an order that would orphan a wallet ledger entry is skipped and reported, while its neighbours still purge', function (): void {
    ['order' => $blocked, 'item' => $blockedItem, 'secret' => $blockedSecret] = purgeTestOrder();

    $account = WalletAccount::factory()->create();
    WalletEntry::factory()->for($account, 'walletAccount')->for($blocked)->create([
        'type' => WalletEntryType::Debit,
    ]);

    ['order' => $healthy] = purgeTestOrder();

    $this->artisan('orders:purge-cancelled')
        ->expectsOutputToContain("with wallet ledger entries: {$blocked->order_number}")
        ->assertSuccessful();

    expect(Order::find($blocked->id))->not->toBeNull()
        ->and(WalletEntry::where('order_id', $blocked->id)->count())->toBe(1)
        // Nothing was half-deleted off the blocked order.
        ->and(OrderItem::find($blockedItem->id))->not->toBeNull()
        ->and(OrderItemSecret::find($blockedSecret->id))->not->toBeNull()
        ->and(SecretAccessLog::where('order_item_secret_id', $blockedSecret->id)->count())->toBe(1)
        ->and(Payment::where('order_id', $blocked->id)->count())->toBe(1)
        // The healthy order beside it still went.
        ->and(Order::find($healthy->id))->toBeNull();
});

test('an order with a receipt is skipped rather than deleting financial paper', function (): void {
    ['order' => $order] = purgeTestOrder();

    $order->receipt()->create([
        'receipt_number' => 'R-'.Str::ulid(),
        'total_halalah' => 5000,
        'storage_path' => 'receipts/none.pdf',
        'content_hash' => hash('sha256', 'none'),
        'issued_at' => now()->subHours(30),
    ]);

    $this->artisan('orders:purge-cancelled')
        ->expectsOutputToContain("with receipts: {$order->order_number}")
        ->assertSuccessful();

    expect(Order::find($order->id))->not->toBeNull()
        ->and($order->receipt()->count())->toBe(1);
});

test('the grace period follows configuration', function (): void {
    config()->set('services.orders.purge_cancelled_grace_hours', 48);

    ['order' => $inside] = purgeTestOrder();

    $this->artisan('orders:purge-cancelled')->assertSuccessful();
    expect(Order::find($inside->id))->not->toBeNull();

    config()->set('services.orders.purge_cancelled_grace_hours', 1);

    ['order' => $outside] = purgeTestOrder(['cancelled_at' => now()->subHours(2)]);

    $this->artisan('orders:purge-cancelled')->assertSuccessful();
    expect(Order::find($outside->id))->toBeNull();
});

test('the purge is scheduled hourly beside the other maintenance commands', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'orders:purge-cancelled'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
