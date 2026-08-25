<?php

namespace App\Actions\Checkout;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentManager;
use App\Support\OrderClosingNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ReconcilePaylinkPayment
{
    public function __construct(
        private PaymentManager $payments,
        private ReleaseOrderWalletFunds $releaseOrderWalletFunds,
    ) {}

    public function execute(Payment $payment): Payment
    {
        if ($payment->provider !== 'paylink'
            || ! is_string($payment->provider_payment_id)
            || $payment->provider_payment_id === '') {
            throw new CheckoutUnavailable('The payment cannot be verified.');
        }

        $invoice = $this->payments->gateway()->getInvoice($payment->provider_payment_id);
        $payment->loadMissing('order');
        $order = $payment->order;

        if (! $order instanceof Order
            || $invoice->transactionNo !== $payment->provider_payment_id
            || $invoice->orderNumber !== $order->order_number
            || $invoice->amountHalalah !== $payment->amount_halalah
            || $invoice->currency !== 'SAR') {
            throw new CheckoutUnavailable('Paylink returned a mismatched invoice.');
        }

        return DB::transaction(function () use ($invoice, $payment): Payment {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->sole();
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->sole();
            $metadata = [
                'payment_url' => $invoice->paymentUrl,
                'provider_status' => $invoice->status,
            ];

            if ($invoice->paymentMethod !== null) {
                $metadata['payment_method'] = $invoice->paymentMethod;
            }

            if ($invoice->status === 'paid') {
                $locked->forceFill([
                    'status' => PaymentStatus::Paid,
                    'captured_halalah' => $invoice->amountHalalah,
                    'paid_at' => $locked->paid_at ?? now(),
                    'provider_metadata' => $metadata,
                ])->save();

                if ($order->status === OrderStatus::PendingPayment) {
                    $order->forceFill(['status' => OrderStatus::Received, 'paid_at' => now()])->save();
                    $order->items()->update(['status' => OrderItemStatus::Received->value]);
                    $order->statusHistory()->create([
                        'status' => OrderStatusHistoryStatus::Received,
                        'metadata' => ['source' => 'paylink'],
                    ]);
                    IntegrationEvent::create([
                        'event_id' => (string) Str::ulid(),
                        'event_type' => 'order.paid',
                        'aggregate_type' => 'order',
                        'aggregate_id' => $order->public_id,
                        'schema_version' => 1,
                        'payload' => [
                            'order_public_id' => $order->public_id,
                            'order_number' => $order->order_number,
                            'locale' => $order->locale,
                            'currency' => $order->currency,
                            'total_halalah' => $order->total_halalah,
                            'item_count' => $order->items()->count(),
                        ],
                        'status' => 'pending',
                        'idempotency_key' => 'order-paid:'.$order->id,
                        'attempts' => 0,
                        'available_at' => now(),
                    ]);
                }
            } elseif ($invoice->status === 'cancelled') {
                $locked->forceFill([
                    'status' => PaymentStatus::Cancelled,
                    'provider_metadata' => $metadata,
                ])->save();

                if ($order->status === OrderStatus::PendingPayment) {
                    // The wallet was debited at placement, so a cancelled
                    // invoice must give that money back or it is destroyed -
                    // no other path credits a cancelled order.
                    $this->releaseOrderWalletFunds->execute($order, 'paylink_cancelled');

                    $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();
                    $order->items()->update(['status' => OrderItemStatus::Cancelled->value]);
                    $order->statusHistory()->create([
                        'status' => OrderStatusHistoryStatus::Cancelled,
                        ...OrderClosingNote::reason('payment_cancelled'),
                        'metadata' => ['source' => 'paylink'],
                    ]);
                }
            } else {
                $locked->forceFill(['provider_metadata' => $metadata])->save();
            }

            return $locked->fresh();
        }, attempts: 3);
    }
}
