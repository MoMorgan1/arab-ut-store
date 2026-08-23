<?php

use App\Actions\Checkout\PlaceOrder;
use App\Actions\Checkout\RefundPaylinkOrder;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Enums\WalletEntryType;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

/** @return array{user: User, cart: Cart, item: CartItem, variant: ProductVariant} */
function createWalletCartFixture(int $unitPriceHalalah = 1250, array $cartAttributes = []): array
{
    static $sequence = 0;
    $sequence++;
    $user = User::factory()->create([
        'phone' => '+9665'.str_pad((string) $sequence, 8, '0', STR_PAD_LEFT),
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
        'price_halalah' => $unitPriceHalalah,
        'sale_price_halalah' => null,
        'price_version' => 1,
        'is_active' => true,
    ]);

    $cart = Cart::create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
        ...$cartAttributes,
    ]);

    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => $unitPriceHalalah,
        'total_halalah' => $unitPriceHalalah,
        'configuration' => [
            'service_type' => 'sbc',
            'platform' => 'playstation',
            'market' => 'console',
            'completion_count' => 1,
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => 1,
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

    return compact('user', 'cart', 'item', 'variant');
}

function creditUserWallet(User $user, int $amountHalalah): WalletAccount
{
    $writer = app(WalletLedgerWriter::class);
    $account = $writer->lockAccountFor($user->id);

    $writer->append($account, [
        'type' => WalletEntryType::Credit,
        'amount_halalah' => $amountHalalah,
        'balance_delta_halalah' => $amountHalalah,
        'order_id' => null,
        'refund_id' => null,
        'created_by_user_id' => null,
        'reference' => 'test-credit:'.uniqid('', true),
        'metadata' => ['reason' => 'test fixture'],
    ]);

    return $account->fresh();
}

test('wallet toggle on and off persists on the active cart for bilingual routes', function (string $url, string $locale): void {
    ['user' => $user, 'cart' => $cart] = createWalletCartFixture();

    $this->actingAs($user)
        ->postJson($url, ['use' => true])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.use_wallet', true);

    expect($cart->fresh()->use_wallet)->toBeTrue();

    $this->actingAs($user)
        ->postJson($url, ['use' => false])
        ->assertOk()
        ->assertJsonPath('data.use_wallet', false);

    expect($cart->fresh()->use_wallet)->toBeFalse();
})->with([
    'Arabic route' => ['/cart/wallet', 'ar'],
    'English route' => ['/en/cart/wallet', 'en'],
]);

test('wallet toggle validates input and requires active cart', function (): void {
    ['user' => $user] = createWalletCartFixture();

    $this->actingAs($user)
        ->postJson('/cart/wallet', ['use' => 'invalid'])
        ->assertUnprocessable();

    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->postJson('/cart/wallet', ['use' => true])
        ->assertNotFound();
});

test('cart page reflects wallet balance and toggle status for authenticated users and guests', function (): void {
    $guestResponse = $this->get('/cart')->assertOk();
    expect($guestResponse->inertiaPage()['props']['cartPage']['checkout']['walletBalanceHalalah'] ?? null)->toBe(0);

    ['user' => $user, 'cart' => $cart] = createWalletCartFixture();
    creditUserWallet($user, 7500);

    $userResponse = $this->actingAs($user)->get('/cart')->assertOk();
    $page = $userResponse->inertiaPage()['props'];

    expect($page['cartPage']['checkout']['walletBalanceHalalah'])->toBe(7500)
        ->and($page['cart']['useWallet'])->toBeFalse()
        ->and($page['cartPage']['checkout']['walletToggleUrl'])->toBe('/cart/wallet');

    $cart->update(['use_wallet' => true]);

    $updatedResponse = $this->actingAs($user)->get('/cart')->assertOk();
    expect($updatedResponse->inertiaPage()['props']['cart']['useWallet'])->toBeTrue();
});

test('place order with partial wallet debits ledger, sets order wallet_halalah and charges paylink remainder', function (): void {
    ['user' => $user, 'cart' => $cart] = createWalletCartFixture(unitPriceHalalah: 1250);
    $cart->update(['use_wallet' => true]);
    creditUserWallet($user, 500);

    $result = app(PlaceOrder::class)->execute($user, 'ar', 'partial-wallet-test');
    $order = $result->order->fresh(['payments']);

    expect($result->replayed)->toBeFalse()
        ->and($order->subtotal_halalah)->toBe(1250)
        ->and($order->discount_halalah)->toBe(0)
        ->and($order->wallet_halalah)->toBe(500)
        ->and($order->payment_halalah)->toBe(750)
        ->and($order->total_halalah)->toBe(1250)
        ->and($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($result->payment->provider)->toBe('paylink')
        ->and($result->payment->amount_halalah)->toBe(750);

    $walletAccount = WalletAccount::query()->where('user_id', $user->id)->first();
    expect((int) $walletAccount->balance_halalah)->toBe(0);

    $debitEntry = WalletEntry::query()
        ->where('wallet_account_id', $walletAccount->id)
        ->where('reference', "order-wallet:{$order->id}")
        ->first();

    expect($debitEntry)->not->toBeNull()
        ->and($debitEntry->type)->toBe(WalletEntryType::Debit)
        ->and($debitEntry->amount_halalah)->toBe(500)
        ->and($debitEntry->order_id)->toBe($order->id);
});

test('place order with full wallet payment settles order as received without paylink payment', function (): void {
    ['user' => $user, 'cart' => $cart] = createWalletCartFixture(unitPriceHalalah: 1250);
    $cart->update(['use_wallet' => true]);
    creditUserWallet($user, 2000);

    $result = app(PlaceOrder::class)->execute($user, 'ar', 'full-wallet-test');
    $order = $result->order->fresh(['items', 'payments', 'statusHistory']);

    expect($result->replayed)->toBeFalse()
        ->and($order->subtotal_halalah)->toBe(1250)
        ->and($order->wallet_halalah)->toBe(1250)
        ->and($order->payment_halalah)->toBe(0)
        ->and($order->total_halalah)->toBe(1250)
        ->and($order->status)->toBe(OrderStatus::Received)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->items->first()->status)->toBe(OrderItemStatus::Received)
        ->and($result->payment->provider)->toBe('wallet')
        ->and($result->payment->status)->toBe(PaymentStatus::Paid)
        ->and($result->payment->amount_halalah)->toBe(0);

    $walletAccount = WalletAccount::query()->where('user_id', $user->id)->first();
    expect((int) $walletAccount->balance_halalah)->toBe(750);

    $debitEntry = WalletEntry::query()
        ->where('wallet_account_id', $walletAccount->id)
        ->where('reference', "order-wallet:{$order->id}")
        ->first();

    expect($debitEntry)->not->toBeNull()
        ->and($debitEntry->amount_halalah)->toBe(1250);
});

test('insufficient wallet balance uses only what exists and charges remainder', function (): void {
    ['user' => $user, 'cart' => $cart] = createWalletCartFixture(unitPriceHalalah: 1250);
    $cart->update(['use_wallet' => true]);
    creditUserWallet($user, 300);

    $result = app(PlaceOrder::class)->execute($user, 'ar', 'insufficient-balance-test');
    $order = $result->order->fresh();

    expect($order->wallet_halalah)->toBe(300)
        ->and($order->payment_halalah)->toBe(950)
        ->and($result->payment->amount_halalah)->toBe(950);

    $walletAccount = WalletAccount::query()->where('user_id', $user->id)->first();
    expect((int) $walletAccount->balance_halalah)->toBe(0);
});

test('idempotent replay does not debit wallet balance a second time', function (): void {
    ['user' => $user, 'cart' => $cart] = createWalletCartFixture(unitPriceHalalah: 1250);
    $cart->update(['use_wallet' => true]);
    creditUserWallet($user, 2000);

    $placeOrder = app(PlaceOrder::class);
    $first = $placeOrder->execute($user, 'ar', 'idempotency-wallet-key');
    $second = $placeOrder->execute($user, 'ar', 'idempotency-wallet-key');

    expect($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeTrue()
        ->and($first->order->id)->toBe($second->order->id);

    $walletAccount = WalletAccount::query()->where('user_id', $user->id)->first();
    expect((int) $walletAccount->balance_halalah)->toBe(750);

    $debitCount = WalletEntry::query()
        ->where('wallet_account_id', $walletAccount->id)
        ->where('reference', "order-wallet:{$first->order->id}")
        ->count();

    expect($debitCount)->toBe(1);
});

test('paylink checkout endpoint returns paid status directly when order is fully paid by wallet', function (): void {
    ['user' => $user, 'cart' => $cart] = createWalletCartFixture(unitPriceHalalah: 1250);
    $cart->update(['use_wallet' => true]);
    creditUserWallet($user, 2000);

    $this->actingAs($user)
        ->postJson('/checkout/paylink', [], ['Idempotency-Key' => 'api-full-wallet-checkout'])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.paymentUrl', null)
        ->assertJsonStructure(['data' => ['orderUrl', 'paymentUrl', 'status']]);
});

test('refund returns wallet part to wallet ledger before paylink refund and cashback reversal', function (): void {
    Http::fake(fn ($request) => str_contains($request->url(), '/partner/auth')
        ? Http::response(['id_token' => 'partner-token'])
        : Http::response([
            'id' => 237,
            'orderNumber' => 'AUT-WALLET-REFUND-1',
            'amount' => 15.00,
            'currency' => 'SAR',
            'refundReason' => 'Customer request.',
            'createDatetime' => 1716194603030,
        ]));
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINTESTTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $customer = User::factory()->create();
    creditUserWallet($customer, 1000);

    $order = Order::factory()->for($customer)->create([
        'order_number' => 'AUT-WALLET-REFUND-1',
        'status' => OrderStatus::Received,
        'currency' => 'SAR',
        'subtotal_halalah' => 2500,
        'discount_halalah' => 0,
        'wallet_halalah' => 1000,
        'payment_halalah' => 1500,
        'total_halalah' => 2500,
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
        'unit_price_halalah' => 2500,
        'subtotal_halalah' => 2500,
        'discount_halalah' => 0,
        'total_halalah' => 2500,
        'configuration' => [],
    ]);

    $payment = $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => '1710000000100',
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => 1500,
        'captured_halalah' => 1500,
        'refunded_halalah' => 0,
        'idempotency_key' => 'paylink-payment-fixture-'.$order->id,
        'paid_at' => now(),
    ]);

    Http::fake([
        'https://restpilot.paylink.sa/api/auth' => Http::response([
            'id_token' => 'jwt-token',
        ], 200),
        'https://restpilot.paylink.sa/api/refund' => Http::response([
            'orderNumber' => $order->order_number,
            'refundTransactionNo' => 'REFUND-1710000000100',
            'amount' => 15.0,
            'currency' => 'SAR',
            'timestamp' => now()->getTimestampMs(),
        ], 200),
    ]);

    $refund = app(RefundPaylinkOrder::class)->execute($order, 'Customer requested refund.', $admin);

    expect($refund->status)->toBe('completed')
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded);

    $customerWallet = WalletAccount::query()->where('user_id', $customer->id)->first();
    expect((int) $customerWallet->balance_halalah)->toBe(2000);

    $refundEntry = WalletEntry::query()
        ->where('wallet_account_id', $customerWallet->id)
        ->where('reference', "order-wallet-refund:{$refund->id}")
        ->first();

    expect($refundEntry)->not->toBeNull()
        ->and($refundEntry->type)->toBe(WalletEntryType::Refund)
        ->and($refundEntry->amount_halalah)->toBe(1000)
        ->and($refundEntry->order_id)->toBe($order->id)
        ->and($refundEntry->refund_id)->toBe($refund->id);
});
