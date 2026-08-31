<?php

use App\Actions\Checkout\StartPaylinkPayment;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $attributes
 * @return array{order: Order, payment: Payment}
 */
function resumePaylinkOrderPayment(array $attributes = []): array
{
    $user = User::factory()->create(['phone' => '+966500000099']);
    $order = Order::factory()->for($user)->has(OrderItem::factory(), 'items')->create();
    $payment = Payment::create([
        'order_id' => $order->id,
        'provider' => 'paylink',
        'status' => PaymentStatus::Pending,
        'currency' => 'SAR',
        'amount_halalah' => 10_000,
        'idempotency_key' => 'resume-paylink:'.Str::ulid(),
        ...$attributes,
    ]);

    return ['order' => $order, 'payment' => $payment];
}

function fakeResumePaylinkInvoice(string $orderStatus = 'Paid'): void
{
    config()->set('services.paylink.environment', 'test');
    config()->set('services.paylink.api_id', 'merchant-id');
    config()->set('services.paylink.secret_key', 'merchant-secret');
    Cache::flush();
    Http::fake(function ($request) use ($orderStatus) {
        if (str_ends_with($request->url(), '/api/auth')) {
            return Http::response(['id_token' => 'merchant-token']);
        }

        $pending = strtolower($orderStatus) === 'pending';

        return Http::response([
            'success' => true,
            'transactionNo' => '1710000000099',
            'orderStatus' => $orderStatus,
            'amount' => 100.00,
            'url' => $pending ? 'https://payment.paylink.sa/pay/info/1710000000099' : null,
            'gatewayOrderRequest' => ['orderNumber' => Order::sole()->order_number, 'currency' => 'SAR'],
            'paymentReceipt' => strtolower($orderStatus) === 'paid' ? ['paymentMethod' => 'mada'] : null,
        ]);
    });
}

test('resuming checkout never resets a payment the provider already settled', function (PaymentStatus $status, int $refundedHalalah) {
    fakeResumePaylinkInvoice('Paid');
    ['order' => $order, 'payment' => $payment] = resumePaylinkOrderPayment([
        'provider_payment_id' => '1710000000099',
        'status' => $status,
        'captured_halalah' => 10_000,
        'refunded_halalah' => $refundedHalalah,
        'paid_at' => '2026-08-30 12:00:00',
        'provider_metadata' => ['payment_url' => null, 'provider_status' => 'paid', 'payment_method' => 'mada'],
    ]);

    $invoice = app(StartPaylinkPayment::class)->execute($order, $payment);

    $payment = $payment->fresh();
    expect($invoice->status)->toBe('paid')
        ->and($payment->status)->toBe($status)
        ->and($payment->captured_halalah)->toBe(10_000)
        ->and($payment->refunded_halalah)->toBe($refundedHalalah)
        ->and($payment->paid_at?->format('Y-m-d H:i:s'))->toBe('2026-08-30 12:00:00')
        ->and($payment->provider_payment_id)->toBe('1710000000099')
        ->and($payment->provider_metadata)->toBe([
            'payment_url' => null,
            'provider_status' => 'paid',
            'payment_method' => 'mada',
        ]);
})->with([
    'paid' => [PaymentStatus::Paid, 0],
    'partially refunded' => [PaymentStatus::PartiallyRefunded, 4_000],
    'refunded' => [PaymentStatus::Refunded, 10_000],
]);

test('resuming checkout still updates a genuinely pending payment exactly as before', function () {
    fakeResumePaylinkInvoice('Pending');
    ['order' => $order, 'payment' => $payment] = resumePaylinkOrderPayment();

    $invoice = app(StartPaylinkPayment::class)->execute($order, $payment);

    $payment = $payment->fresh();
    expect($invoice->paymentUrl)->toBe('https://payment.paylink.sa/pay/info/1710000000099')
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider_payment_id)->toBe('1710000000099')
        ->and($payment->captured_halalah)->toBe(0)
        ->and($payment->paid_at)->toBeNull()
        ->and($payment->provider_metadata)->toBe([
            'payment_url' => 'https://payment.paylink.sa/pay/info/1710000000099',
            'provider_status' => 'pending',
        ]);
});
