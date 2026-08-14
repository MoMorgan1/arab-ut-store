<?php

use App\Actions\Checkout\PlaceOrder;
use App\Actions\Checkout\StartPaylinkPayment;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\IdempotencyConflict;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\OrderItemSecret;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** @return array{user: User, cart: Cart, item: CartItem, variant: ProductVariant} */
function checkoutSbcCart(array $changes = []): array
{
    static $phoneSequence = 0;
    $phoneSequence++;
    $user = User::factory()->create([
        'phone' => '+9665'.str_pad((string) $phoneSequence, 8, '0', STR_PAD_LEFT),
        'phone_verified_at' => now(),
    ]);
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'name_ar' => 'تحدي لاعب',
        'name_en' => 'Player challenge',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'name_ar' => 'بلايستيشن وإكس بوكس',
        'name_en' => 'PlayStation / Xbox',
        'price_halalah' => 1250,
        'sale_price_halalah' => null,
        'price_version' => 4,
        'is_active' => true,
    ]);
    $cart = Cart::create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 1250,
        'total_halalah' => 1250,
        'configuration' => [
            'service_type' => 'sbc',
            'platform' => 'playstation',
            'market' => 'console',
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => 4,
        ],
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 3],
        'retained_until' => null,
        'deleted_at' => null,
    ]);
    $secret->encrypted_payload = [
        'ea_email' => 'owner@example.test',
        'ea_password' => 'Opaque password',
        'backup_codes' => ['12345678', '23456789', '34567890'],
    ];
    $secret->save();

    foreach ($changes as $target => $attributes) {
        ${$target}->update($attributes);
    }

    return compact('user', 'cart', 'item', 'variant');
}

test('checkout atomically snapshots a verified users active cart and encrypted credentials', function () {
    ['user' => $user, 'cart' => $cart, 'variant' => $variant] = checkoutSbcCart();

    $result = app(PlaceOrder::class)->execute($user, 'ar', 'checkout-first-order');
    $order = $result->order->fresh(['items.secret', 'payments', 'statusHistory']);

    expect($result->replayed)->toBeFalse()
        ->and($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->currency)->toBe('SAR')
        ->and($order->subtotal_halalah)->toBe(1250)
        ->and($order->payment_halalah)->toBe(1250)
        ->and($order->total_halalah)->toBe(1250)
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->product_variant_id)->toBe($variant->id)
        ->and($order->items->first()->status)->toBe(OrderItemStatus::PendingPayment)
        ->and($order->items->first()->name_ar)->toBe('تحدي لاعب')
        ->and($order->items->first()->configuration)->toMatchArray(['price_version' => 4])
        ->and($order->payments)->toHaveCount(1)
        ->and($order->payments->first()->status)->toBe(PaymentStatus::Pending)
        ->and($order->payments->first()->provider)->toBe('paylink')
        ->and($order->statusHistory)->toHaveCount(1)
        ->and(OrderStatusHistory::sole()->status->value)->toBe('pending_payment')
        ->and($cart->fresh()->status)->toBe('converted')
        ->and($cart->fresh()->active_owner_key)->toBeNull();

    $orderSecret = OrderItemSecret::sole();
    expect($orderSecret->encrypted_payload)->toBe([
        'ea_email' => 'owner@example.test',
        'ea_password' => 'Opaque password',
        'backup_codes' => ['12345678', '23456789', '34567890'],
    ])->and(DB::table('order_item_secrets')->value('encrypted_payload'))
        ->not->toContain('owner@example.test')
        ->not->toContain('Opaque password')
        ->not->toContain('12345678')
        ->and(IdempotencyKey::sole()->response_body)
        ->not->toContain('owner@example.test')
        ->not->toContain('12345678');
});

test('an exact checkout retry replays one order while another user conflicts', function () {
    ['user' => $user] = checkoutSbcCart();

    $created = app(PlaceOrder::class)->execute($user, 'en', 'checkout-replay');
    $replayed = app(PlaceOrder::class)->execute($user, 'en', 'checkout-replay');

    expect($replayed->replayed)->toBeTrue()
        ->and($replayed->order->is($created->order))->toBeTrue()
        ->and(Order::count())->toBe(1)
        ->and(Payment::count())->toBe(1);

    ['user' => $other] = checkoutSbcCart();
    expect(fn () => app(PlaceOrder::class)->execute($other, 'ar', 'checkout-replay'))
        ->toThrow(IdempotencyConflict::class);
});

test('checkout requires a verified phone and a nonempty active cart', function () {
    ['user' => $unverified] = checkoutSbcCart();
    $unverified->forceFill(['phone_verified_at' => null])->save();

    expect(fn () => app(PlaceOrder::class)->execute($unverified, 'ar', 'checkout-unverified'))
        ->toThrow(CheckoutUnavailable::class, 'A verified mobile number is required.');

    $emptyUser = User::factory()->create([
        'phone' => '+966500000002',
        'phone_verified_at' => now(),
    ]);
    Cart::create(['user_id' => $emptyUser->id, 'status' => 'active', 'currency' => 'SAR']);

    expect(fn () => app(PlaceOrder::class)->execute($emptyUser, 'ar', 'checkout-empty'))
        ->toThrow(CheckoutUnavailable::class, 'The cart is empty.');
});

test('checkout fails closed when the product price or credential snapshot is stale', function (string $target, array $changes, string $message) {
    $state = checkoutSbcCart([$target => $changes]);

    expect(fn () => app(PlaceOrder::class)->execute($state['user'], 'ar', 'checkout-stale-'.$target))
        ->toThrow(CheckoutUnavailable::class, $message)
        ->and(Order::count())->toBe(0)
        ->and($state['cart']->fresh()->status)->toBe('active');
})->with([
    'price changed' => ['variant', ['price_halalah' => 1300, 'price_version' => 5], 'The cart price has changed.'],
    'unit price inconsistent' => ['item', ['unit_price_halalah' => 1200], 'The cart price has changed.'],
    'variant inactive' => ['variant', ['is_active' => false], 'A cart item is unavailable.'],
]);

test('checkout fails closed when required credentials are missing or deleted', function (bool $deleteRow) {
    $state = checkoutSbcCart();
    $secret = $state['item']->secret()->sole();

    if ($deleteRow) {
        $secret->delete();
    } else {
        $secret->update(['deleted_at' => now()]);
    }

    expect(fn () => app(PlaceOrder::class)->execute($state['user'], 'ar', 'checkout-secret-'.(int) $deleteRow))
        ->toThrow(CheckoutUnavailable::class, 'EA account details are required.')
        ->and(Order::count())->toBe(0);
})->with([
    'missing' => true,
    'soft deleted marker' => false,
]);

test('a database failure rolls back the order payment secret idempotency claim and cart conversion', function () {
    $state = checkoutSbcCart();
    DB::statement("CREATE TRIGGER checkout_secret_abort BEFORE INSERT ON order_item_secrets BEGIN SELECT RAISE(ABORT, 'checkout-secret-failure'); END");

    expect(fn () => app(PlaceOrder::class)->execute($state['user'], 'ar', 'checkout-rollback'))
        ->toThrow(QueryException::class);

    expect(Order::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and(OrderItemSecret::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(0)
        ->and($state['cart']->fresh()->status)->toBe('active');
});

test('starting payment creates one Paylink invoice and stores only safe provider fields', function () {
    config()->set('services.paylink.environment', 'test');
    config()->set('services.paylink.api_id', 'merchant-id');
    config()->set('services.paylink.secret_key', 'merchant-secret');
    Cache::flush();
    ['user' => $user] = checkoutSbcCart();
    $placed = app(PlaceOrder::class)->execute($user, 'ar', 'checkout-start-payment');
    Http::fake(function ($request) use ($placed) {
        if ($request->url() === 'https://restpilot.paylink.sa/api/auth') {
            return Http::response(['id_token' => 'merchant-token']);
        }

        return Http::response([
            'success' => true,
            'transactionNo' => '1710000000001',
            'orderStatus' => 'Pending',
            'amount' => 12.50,
            'url' => 'https://payment.paylink.sa/pay/info/1710000000001',
            'gatewayOrderRequest' => ['orderNumber' => $placed->order->order_number, 'currency' => 'SAR'],
        ]);
    });

    $invoice = app(StartPaylinkPayment::class)->execute($placed->order, $placed->payment);
    $payment = $placed->payment->fresh();

    expect($invoice->paymentUrl)->toBe('https://payment.paylink.sa/pay/info/1710000000001')
        ->and($payment->provider_payment_id)->toBe('1710000000001')
        ->and($payment->provider_metadata)->toBe([
            'payment_url' => 'https://payment.paylink.sa/pay/info/1710000000001',
            'provider_status' => 'pending',
        ])
        ->and(json_encode($payment->provider_metadata))->not->toContain('merchant-secret');
});
