<?php

use App\Actions\Checkout\StartPaylinkPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('an invoice raised for an order that was cancelled meanwhile is closed again and never attached', function (): void {
    // The cancel landed between the pending check and createInvoice: the
    // gateway now holds a live invoice for a cancelled order. It must be
    // closed and the local payment row left untouched.
    $user = User::factory()->create(['phone' => '+966500000009', 'phone_verified_at' => now()]);
    $order = Order::factory()->for($user)->create([
        'status' => OrderStatus::Cancelled,
        'paid_at' => null,
        'locale' => 'ar',
        'payment_halalah' => 10_000,
        'total_halalah' => 10_000,
        'currency' => 'SAR',
    ]);
    OrderItem::factory()->for($order)->create(['total_halalah' => 10_000]);
    config()->set('app.url', 'https://store.arab-ut.test');
    $payment = Payment::query()->create([
        'public_id' => (string) Str::ulid(),
        'order_id' => $order->id,
        'provider' => 'paylink',
        'provider_payment_id' => null,
        'idempotency_key' => (string) Str::ulid(),
        'status' => PaymentStatus::Cancelled,
        'amount_halalah' => 10_000,
        'captured_halalah' => 0,
        'currency' => 'SAR',
    ]);
    config()->set('services.paylink.environment', 'test');
    config()->set('services.paylink.api_id', 'merchant-id');
    config()->set('services.paylink.secret_key', 'merchant-secret');
    Cache::flush();
    Http::fake(function ($request) use ($order) {
        if (str_ends_with($request->url(), '/api/auth')) {
            return Http::response(['id_token' => 'merchant-token']);
        }

        if (str_ends_with($request->url(), '/api/cancelInvoice')) {
            return Http::response(['success' => true]);
        }

        return Http::response([
            'success' => true,
            'transactionNo' => 'INV-RACE',
            'orderStatus' => 'Pending',
            'amount' => 100.00,
            'url' => 'https://payment.paylink.sa/pay/info/INV-RACE',
            'gatewayOrderRequest' => ['orderNumber' => $order->order_number, 'currency' => 'SAR'],
            'paymentReceipt' => null,
        ]);
    });

    expect(fn () => app(StartPaylinkPayment::class)->execute($order, $payment))
        ->toThrow(CheckoutUnavailable::class);

    expect($payment->fresh()->provider_payment_id)->toBeNull()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Cancelled);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/cancelInvoice')
        && $request['transactionNo'] === 'INV-RACE');
});
