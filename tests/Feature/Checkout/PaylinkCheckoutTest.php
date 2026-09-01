<?php

use App\Actions\Checkout\ReconcilePaylinkPayment;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\WalletEntryType;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\Cart;
use App\Models\CartItemSecret;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WalletEntry;
use App\Notifications\OrderPaidNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{user: User, variant: ProductVariant} */
function paylinkCheckoutCart(bool $verified = true): array
{
    $user = User::factory()->create([
        'phone' => $verified ? '+966500000099' : null,
        'phone_verified_at' => $verified ? now() : null,
    ]);
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'name_ar' => 'تحدي الدفع',
        'name_en' => 'Checkout challenge',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => 1250,
        'price_version' => 8,
        'is_active' => true,
    ]);
    $cart = Cart::create(['user_id' => $user->id, 'status' => 'active', 'currency' => 'SAR']);
    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 1250,
        'total_halalah' => 1250,
        'configuration' => [
            'service_type' => 'sbc',
            'platform' => 'playstation',
            'market' => 'console',
            'completion_count' => 1,
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => 8,
        ],
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 3],
    ]);
    $secret->encrypted_payload = [
        'ea_email' => 'checkout@example.test',
        'ea_password' => 'safe password',
        'backup_codes' => ['11111111', '22222222', '33333333'],
    ];
    $secret->save();

    return compact('user', 'variant');
}

/**
 * The storefront always sends both expected totals, and checkout now refuses
 * without them, so every POST here has to carry what the cart showed.
 *
 * @return array<string, string>
 */
function paylinkCheckoutHeaders(string $key, int $orderTotalHalalah = 1250, ?int $payableHalalah = null): array
{
    return [
        'Idempotency-Key' => $key,
        'X-Expected-Order-Total-Halalah' => (string) $orderTotalHalalah,
        'X-Expected-Total-Halalah' => (string) ($payableHalalah ?? $orderTotalHalalah),
    ];
}

function fakePaylinkCheckout(?string $orderNumber = null, string $getStatus = 'Pending', float $amount = 12.50): void
{
    config()->set('services.paylink.environment', 'test');
    config()->set('services.paylink.api_id', 'merchant-id');
    config()->set('services.paylink.secret_key', 'merchant-secret');
    Cache::flush();
    Http::fake(function ($request) use ($getStatus, $orderNumber, $amount) {
        if (str_ends_with($request->url(), '/api/auth')) {
            return Http::response(['id_token' => 'merchant-token']);
        }

        $body = $request->data();
        $resolvedOrder = $orderNumber ?? ($body['orderNumber'] ?? Order::sole()->order_number);
        $status = str_contains($request->url(), '/api/getInvoice/') ? $getStatus : 'Pending';

        return Http::response([
            'success' => true,
            'transactionNo' => '1710000000099',
            'orderStatus' => $status,
            'amount' => $amount,
            'url' => strtolower($status) === 'pending'
                ? 'https://payment.paylink.sa/pay/info/1710000000099'
                : null,
            'gatewayOrderRequest' => ['orderNumber' => $resolvedOrder, 'currency' => 'SAR'],
            'paymentReceipt' => strtolower($status) === 'paid' ? ['paymentMethod' => 'mada'] : null,
        ]);
    });
}

test('checkout requires authentication and never creates a guest order', function () {
    paylinkCheckoutCart();

    $this->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-guest'))
        ->assertUnauthorized()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(Order::count())->toBe(0);
});

test('a verified customer gets one safe Paylink URL and exact retries reuse the invoice', function () {
    ['user' => $user] = paylinkCheckoutCart();
    fakePaylinkCheckout();

    $created = $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-created'));
    $replayed = $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-created'));

    $created->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.paymentUrl', 'https://payment.paylink.sa/pay/info/1710000000099')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.orderUrl', fn (string $url): bool => str_starts_with($url, '/orders/'));
    $replayed->assertOk()->assertJsonPath('data.paymentUrl', 'https://payment.paylink.sa/pay/info/1710000000099');
    expect(Order::count())->toBe(1)
        ->and(Payment::count())->toBe(1)
        ->and(Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/addInvoice')))->toHaveCount(1)
        ->and($created->getContent())->not->toContain('checkout@example.test')
        ->not->toContain('safe password')
        ->not->toContain('11111111');
});

test('checkout returns stable errors for phone verification and changed carts', function () {
    ['user' => $unverified] = paylinkCheckoutCart(false);

    $this->actingAs($unverified)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-phone'))->assertUnprocessable()->assertJsonPath('error.code', 'phone_verification_required');

    ['user' => $user, 'variant' => $variant] = paylinkCheckoutCart();
    $variant->update(['price_halalah' => 1300, 'price_version' => 9]);

    // The cart is repriced rather than refused, and the customer is asked to
    // confirm the new figure before anything is charged.
    $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-stale'))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'cart_repriced')
        ->assertJsonPath('repricing.orderTotalHalalah', 1300)
        ->assertJsonPath('repricing.previousOrderTotalHalalah', 1250)
        ->assertJsonPath('repricing.couponRemoved', false);

    expect(Order::count())->toBe(0);

    fakePaylinkCheckout(amount: 13.00);
    $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-confirmed', 1300))
        ->assertCreated();

    expect(Order::sole()->total_halalah)->toBe(1300);
});

test('checkout refuses when either expected total is missing', function () {
    ['user' => $user] = paylinkCheckoutCart();

    foreach (['X-Expected-Order-Total-Halalah', 'X-Expected-Total-Halalah'] as $omitted) {
        $headers = paylinkCheckoutHeaders('checkout-http-missing-'.$omitted);
        unset($headers[$omitted]);

        $this->actingAs($user)->postJson('/checkout/paylink', [], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'checkout_validation_error');
    }

    expect(Order::count())->toBe(0);
});

test('a Paylink outage preserves the pending order for a safe retry', function () {
    ['user' => $user] = paylinkCheckoutCart();
    config()->set('services.paylink.api_id', null);
    config()->set('services.paylink.secret_key', null);

    $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-recover'))->assertServiceUnavailable()->assertJsonPath('error.code', 'payment_unavailable');

    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1);
    $canonicalOrderUrl = '/my-account/orders/'.Order::sole()->public_id;
    $this->actingAs($user)->get('/orders/'.Order::sole()->public_id)
        ->assertRedirect($canonicalOrderUrl);
    $this->get($canonicalOrderUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.status', 'pending_payment')
            ->where('order.paymentStartUrl', '/orders/'.Order::sole()->public_id.'/payments/paylink'));
    fakePaylinkCheckout(Order::sole()->order_number);

    $this->actingAs($user)
        ->postJson('/orders/'.Order::sole()->public_id.'/payments/paylink')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.paymentUrl', 'https://payment.paylink.sa/pay/info/1710000000099');
    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1);
});

test('a retry whose Paylink invoice is already paid returns the order instead of reopening payment', function () {
    ['user' => $user] = paylinkCheckoutCart();
    config()->set('services.paylink.environment', 'test');
    config()->set('services.paylink.api_id', 'merchant-id');
    config()->set('services.paylink.secret_key', 'merchant-secret');
    Cache::flush();
    $providerStatus = 'Pending';
    Http::fake(function ($request) use (&$providerStatus) {
        if (str_ends_with($request->url(), '/api/auth')) {
            return Http::response(['id_token' => 'merchant-token']);
        }

        $isLookup = str_contains($request->url(), '/api/getInvoice/');
        $status = $isLookup ? $providerStatus : 'Pending';
        $orderNumber = $request->data()['orderNumber'] ?? Order::sole()->order_number;

        return Http::response([
            'success' => true,
            'transactionNo' => '1710000000099',
            'orderStatus' => $status,
            'amount' => 12.50,
            'url' => strtolower($status) === 'pending'
                ? 'https://payment.paylink.sa/pay/info/1710000000099'
                : null,
            'gatewayOrderRequest' => ['orderNumber' => $orderNumber, 'currency' => 'SAR'],
            'paymentReceipt' => strtolower($status) === 'paid' ? ['paymentMethod' => 'mada'] : null,
        ]);
    });

    $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-paid-retry'))->assertCreated()->assertJsonPath('data.status', 'pending');

    $providerStatus = 'Paid';

    $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-paid-retry'))->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.paymentUrl', null)
        ->assertJsonPath('data.orderUrl', '/orders/'.Order::sole()->public_id);

    expect(Order::sole()->status->value)->toBe('received')
        ->and(Payment::sole()->status->value)->toBe('paid');
});

test('only the pending order owner can resume its Paylink payment', function () {
    ['user' => $owner] = paylinkCheckoutCart();
    $otherUser = User::factory()->create();
    fakePaylinkCheckout();
    $this->actingAs($owner)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-owner'))->assertCreated();
    $resumeUrl = '/orders/'.Order::sole()->public_id.'/payments/paylink';

    $this->actingAs($otherUser)->postJson($resumeUrl)->assertNotFound();

    Order::sole()->update(['status' => 'cancelled']);
    $this->actingAs($owner)->postJson($resumeUrl)
        ->assertConflict()
        ->assertJsonPath('error.code', 'payment_unavailable');
});

test('the Paylink return verifies the invoice before marking the owner order received', function () {
    ['user' => $user] = paylinkCheckoutCart();
    fakePaylinkCheckout(getStatus: 'Paid');
    $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-paid'))->assertCreated();
    $this->actingAs($user)->get('/payments/paylink/callback?TransactionNo=1710000000099&OrderNumber='.Order::sole()->order_number)
        ->assertRedirect('/orders/'.Order::sole()->public_id);

    expect(Order::sole()->status->value)->toBe('received')
        ->and(Payment::sole()->status->value)->toBe('paid')
        ->and(Payment::sole()->captured_halalah)->toBe(1250)
        ->and(Payment::sole()->provider_metadata)->toMatchArray(['payment_method' => 'mada']);

    $canonicalOrderUrl = '/my-account/orders/'.Order::sole()->public_id;
    $this->actingAs($user)->get('/orders/'.Order::sole()->public_id)
        ->assertRedirect($canonicalOrderUrl);
    $this->get($canonicalOrderUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/live-order')
            // The row is 'received'; the customer is shown the collapsed state.
            ->where('order.status', 'in_progress')
            ->where('order.total', ['amountMinor' => '1250', 'currency' => 'SAR'])
            ->missing('order.payments'));
});

test('a customer cannot reconcile another customer Paylink payment through the return callback', function () {
    ['user' => $owner] = paylinkCheckoutCart();
    $otherUser = User::factory()->create();
    fakePaylinkCheckout();

    $this->actingAs($owner)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-owner-paylink'))->assertCreated();

    $order = Order::sole();
    $payment = Payment::sole();

    Http::fake();

    $this->actingAs($otherUser)
        ->get('/payments/paylink/callback?TransactionNo='.$payment->provider_payment_id.'&OrderNumber='.$order->order_number)
        ->assertRedirect('/cart');

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    Http::assertNothingSent();
});

test('the authenticated Paylink webhook verifies the invoice before acknowledging payment', function () {
    Notification::fake();
    ['user' => $user] = paylinkCheckoutCart();
    fakePaylinkCheckout(getStatus: 'Paid');
    $webhookToken = str_repeat('w', 64);
    config()->set('services.paylink.webhook_token', $webhookToken);

    $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-webhook'))->assertCreated();

    $order = Order::sole();

    $payload = [
        'amount' => 12.5,
        'merchantOrderNumber' => $order->order_number,
        'orderStatus' => 'Paid',
        'transactionNo' => '1710000000099',
        'apiVersion' => 'v2',
    ];

    $this->postJson('/api/payments/paylink/webhook', $payload, [
        'Authorization' => 'Bearer '.$webhookToken,
    ])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    Notification::assertSentTo($user, OrderPaidNotification::class, function (OrderPaidNotification $notification) use ($order): bool {
        return $notification->order->id === $order->id;
    });
    Notification::assertSentTimes(OrderPaidNotification::class, 1);

    expect(Order::sole()->status->value)->toBe('received')
        ->and(Payment::sole()->status->value)->toBe('paid')
        ->and(Order::sole()->statusHistory()->where('status', 'received')->count())->toBe(1)
        ->and(IntegrationEvent::where('event_type', 'order.paid')->count())->toBe(1)
        ->and(IntegrationEvent::where('event_type', 'order.paid')->sole()->payload)->toMatchArray([
            'order_public_id' => Order::sole()->public_id,
            'order_number' => Order::sole()->order_number,
            'currency' => 'SAR',
            'total_halalah' => 1250,
        ]);

    $this->postJson('/api/payments/paylink/webhook', $payload, [
        'Authorization' => 'Bearer '.$webhookToken,
    ])->assertOk();

    Notification::assertSentTimes(OrderPaidNotification::class, 1);

    expect(Order::sole()->statusHistory()->where('status', 'received')->count())->toBe(1)
        ->and(IntegrationEvent::where('event_type', 'order.paid')->count())->toBe(1)
        ->and(json_encode(IntegrationEvent::sole()->payload, JSON_THROW_ON_ERROR))
        ->not->toContain('checkout@example.test')
        ->not->toContain('safe password')
        ->not->toContain('11111111');
});

test('the Paylink webhook rejects missing or invalid authorization without contacting Paylink', function () {
    $webhookToken = str_repeat('w', 64);
    config()->set('services.paylink.webhook_token', $webhookToken);
    Http::fake();

    $payload = ['transactionNo' => '1710000000099'];

    $this->postJson('/api/payments/paylink/webhook', $payload)
        ->assertUnauthorized()
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson('/api/payments/paylink/webhook', $payload, [
        'Authorization' => 'Bearer wrong-secret',
    ])->assertUnauthorized();

    config()->set('services.paylink.webhook_token', 'short');
    $this->postJson('/api/payments/paylink/webhook', $payload, [
        'Authorization' => 'Bearer short',
    ])->assertUnauthorized();

    Http::assertNothingSent();
});

test('a payment reconciled against a cancelled order is recorded as an anomaly and does not silently pass', function () {
    ['user' => $user] = paylinkCheckoutCart();
    fakePaylinkCheckout(getStatus: 'Paid');
    $webhookToken = str_repeat('w', 64);
    config()->set('services.paylink.webhook_token', $webhookToken);

    $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-http-anomaly'))->assertCreated();

    $order = Order::sole();
    $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

    $loggedWarnings = [];
    Log::listen(function ($log) use (&$loggedWarnings) {
        if ($log->level === 'warning') {
            $loggedWarnings[] = $log->message;
        }
    });

    $payload = [
        'amount' => 12.5,
        'merchantOrderNumber' => $order->order_number,
        'orderStatus' => 'Paid',
        'transactionNo' => '1710000000099',
        'apiVersion' => 'v2',
    ];

    $this->postJson('/api/payments/paylink/webhook', $payload, [
        'Authorization' => 'Bearer '.$webhookToken,
    ])->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Paid)
        ->and(Payment::sole()->captured_halalah)->toBe(1250);

    // No order.paid integration event is published because the order was cancelled
    expect(IntegrationEvent::where('event_type', 'order.paid')->count())->toBe(0);

    // Anomaly is visibly recorded in order status history
    $anomalyHistory = $order->statusHistory()->latest('id')->first();
    expect($anomalyHistory)->not->toBeNull()
        ->and($anomalyHistory->metadata['anomaly'])->toBe('payment_on_non_pending_order')
        ->and($anomalyHistory->metadata['source'])->toBe('paylink')
        ->and($anomalyHistory->metadata['captured_halalah'])->toBe(1250);

    // Warning is logged
    expect($loggedWarnings)->not->toBeEmpty()
        ->and($loggedWarnings[0])->toContain($order->order_number);
});

test('a cancelled Paylink invoice releases order wallet funds and cancels the order', function () {
    ['user' => $user] = paylinkCheckoutCart();
    $cart = Cart::where('user_id', $user->id)->firstOrFail();
    $cart->update(['use_wallet' => true]);

    $writer = app(WalletLedgerWriter::class);
    $account = $writer->lockAccountFor($user->id);
    $writer->append($account, [
        'type' => WalletEntryType::Credit,
        'amount_halalah' => 500,
        'balance_delta_halalah' => 500,
        'order_id' => null,
        'refund_id' => null,
        'created_by_user_id' => null,
        'reference' => 'test-credit-fixture:'.uniqid('', true),
        'metadata' => ['reason' => 'test fixture'],
    ]);

    fakePaylinkCheckout(getStatus: 'Cancelled', amount: 7.50);
    $webhookToken = str_repeat('w', 64);
    config()->set('services.paylink.webhook_token', $webhookToken);

    $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-wallet-cancel', 1250, 750))->assertCreated();

    $order = Order::sole();
    $payment = Payment::sole();
    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->wallet_halalah)->toBe(500)
        ->and($order->payment_halalah)->toBe(750)
        ->and($payment->amount_halalah)->toBe(750)
        ->and((int) $user->fresh()->walletAccount->balance_halalah)->toBe(0);

    $payload = [
        'amount' => 7.5,
        'merchantOrderNumber' => $order->order_number,
        'orderStatus' => 'Cancelled',
        'transactionNo' => '1710000000099',
        'apiVersion' => 'v2',
    ];

    $this->postJson('/api/payments/paylink/webhook', $payload, [
        'Authorization' => 'Bearer '.$webhookToken,
    ])->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->cancelled_at)->not->toBeNull()
        ->and($order->items()->sole()->status)->toBe(OrderItemStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and((int) $user->fresh()->walletAccount->balance_halalah)->toBe(500);

    $refundEntry = WalletEntry::where('wallet_account_id', $user->walletAccount->id)
        ->where('reference', "order-wallet-released:{$order->id}")
        ->first();
    expect($refundEntry)->not->toBeNull()
        ->and($refundEntry->type)->toBe(WalletEntryType::Refund)
        ->and($refundEntry->amount_halalah)->toBe(500);

    // Replaying the webhook must not credit the wallet a second time
    $this->postJson('/api/payments/paylink/webhook', $payload, [
        'Authorization' => 'Bearer '.$webhookToken,
    ])->assertOk();

    expect((int) $user->fresh()->walletAccount->balance_halalah)->toBe(500)
        ->and(WalletEntry::where('wallet_account_id', $user->walletAccount->id)->where('reference', "order-wallet-released:{$order->id}")->count())->toBe(1);
});

test('reconciliation rejects mismatched invoice fields and leaves order pending', function (
    string $field,
    mixed $overrideValue,
    string $expectedException,
) {
    ['user' => $user] = paylinkCheckoutCart();

    config()->set('services.paylink.environment', 'test');
    config()->set('services.paylink.api_id', 'merchant-id');
    config()->set('services.paylink.secret_key', 'merchant-secret');
    Cache::flush();

    Http::fake(function ($request) use ($field, $overrideValue) {
        if (str_ends_with($request->url(), '/api/auth')) {
            return Http::response(['id_token' => 'merchant-token']);
        }

        $order = Order::first();
        $resolvedOrder = $order?->order_number ?? 'AUT-1000';

        if (str_contains($request->url(), '/api/addInvoice')) {
            return Http::response([
                'success' => true,
                'transactionNo' => '1710000000099',
                'orderStatus' => 'Pending',
                'amount' => 12.50,
                'url' => 'https://payment.paylink.sa/pay/info/1710000000099',
                'gatewayOrderRequest' => ['orderNumber' => $resolvedOrder, 'currency' => 'SAR'],
                'paymentReceipt' => null,
            ]);
        }

        $invoiceData = [
            'success' => true,
            'transactionNo' => '1710000000099',
            'orderStatus' => 'Paid',
            'amount' => 12.50,
            'url' => null,
            'gatewayOrderRequest' => [
                'orderNumber' => $resolvedOrder,
                'currency' => 'SAR',
            ],
            'paymentReceipt' => ['paymentMethod' => 'mada'],
        ];

        if ($field === 'transactionNo') {
            $invoiceData['transactionNo'] = $overrideValue;
        } elseif ($field === 'orderNumber') {
            $invoiceData['gatewayOrderRequest']['orderNumber'] = $overrideValue;
        } elseif ($field === 'amount') {
            $invoiceData['amount'] = $overrideValue;
        } elseif ($field === 'currency') {
            $invoiceData['gatewayOrderRequest']['currency'] = $overrideValue;
        }

        return Http::response($invoiceData);
    });

    $this->actingAs($user)->postJson('/checkout/paylink', [], paylinkCheckoutHeaders('checkout-mismatch'))->assertCreated();

    $order = Order::sole();
    $payment = Payment::sole();

    expect(fn () => app(ReconcilePaylinkPayment::class)->execute($payment))
        ->toThrow($expectedException);

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(IntegrationEvent::where('event_type', 'order.paid')->count())->toBe(0);
})->with([
    'wrong transaction number' => ['transactionNo', '9999999999999', CheckoutUnavailable::class],
    'wrong order number' => ['orderNumber', 'AUT-WRONG-999', CheckoutUnavailable::class],
    'wrong amount' => ['amount', 99.00, CheckoutUnavailable::class],
    'wrong currency' => ['currency', 'USD', PaymentGatewayException::class],
]);
