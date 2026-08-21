<?php

namespace App\Actions\Checkout;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Payments\PaymentConfigurationException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Payments\RefundResult;
use App\Services\Payments\PaymentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class RefundPaylinkOrder
{
    public function __construct(private PaymentManager $payments) {}

    public function execute(Order $order, string $reason, User $actor): Refund
    {
        if ($actor->role !== UserRole::Admin) {
            throw new CheckoutUnavailable('Only an admin may refund an order.');
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new CheckoutUnavailable('A valid refund reason is required.');
        }

        $refund = DB::transaction(fn (): Refund => $this->reserve($order, $reason, $actor), attempts: 3);

        if ($refund->status === 'completed') {
            return $refund;
        }

        try {
            $result = $this->payments->gateway()->refund($order->order_number, $reason);
        } catch (PaymentConfigurationException $exception) {
            $refund->delete();

            throw $exception;
        } catch (Throwable $exception) {
            $refund->forceFill(['status' => 'failed'])->save();

            throw $exception;
        }

        if (! $this->matches($result, $order, $refund)) {
            $refund->forceFill(['status' => 'failed'])->save();

            throw new CheckoutUnavailable('Paylink returned a mismatched refund.');
        }

        return DB::transaction(
            fn (): Refund => $this->complete($refund, $result, $actor),
            attempts: 3,
        );
    }

    private function reserve(Order $order, string $reason, User $actor): Refund
    {
        $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->sole();
        $payment = Payment::query()
            ->where('order_id', $lockedOrder->id)
            ->where('provider', 'paylink')
            ->lockForUpdate()
            ->first();

        if (! $payment instanceof Payment) {
            throw new CheckoutUnavailable('The Paylink payment is not refundable.');
        }

        $idempotencyKey = 'paylink:'.hash('sha256', $lockedOrder->id.'|'.$payment->id);
        $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

        if ($existing instanceof Refund) {
            if ($existing->status === 'completed') {
                return $existing;
            }

            throw new CheckoutUnavailable('The refund requires manual review before retrying.');
        }

        if ($payment->status !== PaymentStatus::Paid
            || $payment->currency !== 'SAR'
            || $payment->captured_halalah <= 0
            || $payment->refunded_halalah !== 0) {
            throw new CheckoutUnavailable('The Paylink payment is not refundable.');
        }

        $inserted = DB::table('refunds')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'order_id' => $lockedOrder->id,
            'payment_id' => $payment->id,
            'created_by_user_id' => $actor->id,
            'method' => 'paylink',
            'status' => 'pending',
            'amount_halalah' => $payment->captured_halalah,
            'reason_ar' => $lockedOrder->locale === 'ar' ? $reason : null,
            'reason_en' => $lockedOrder->locale === 'en' ? $reason : null,
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $refund = Refund::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->sole();

        if ($inserted === 1) {
            return $refund;
        }

        if ($refund->status === 'completed') {
            return $refund;
        }

        throw new CheckoutUnavailable('The refund requires manual review before retrying.');
    }

    private function matches(RefundResult $result, Order $order, Refund $refund): bool
    {
        return $result->orderNumber === $order->order_number
            && $result->amountHalalah === $refund->amount_halalah
            && $result->currency === 'SAR';
    }

    private function complete(Refund $refund, RefundResult $result, User $actor): Refund
    {
        $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->sole();

        if ($locked->status === 'completed') {
            return $locked;
        }

        if ($locked->status !== 'pending') {
            throw new CheckoutUnavailable('The refund requires manual review before retrying.');
        }

        $payment = Payment::query()->whereKey($locked->payment_id)->lockForUpdate()->sole();
        $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->sole();
        $payment->forceFill([
            'status' => PaymentStatus::Refunded,
            'refunded_halalah' => $result->amountHalalah,
        ])->save();
        $order->forceFill(['status' => OrderStatus::Refunded])->save();
        $order->items()->update(['status' => OrderItemStatus::Refunded->value]);
        $order->statusHistory()->create([
            'actor_user_id' => $actor->id,
            'status' => OrderStatusHistoryStatus::Refunded,
            'metadata' => ['source' => 'paylink', 'refund_id' => $locked->public_id],
        ]);
        $locked->forceFill([
            'status' => 'completed',
            'provider_refund_id' => $result->providerRefundId,
            'provider_metadata' => [
                'currency' => $result->currency,
                'created_at_ms' => $result->createdAtMilliseconds,
            ],
            'completed_at' => now(),
        ])->save();

        return $locked->fresh();
    }
}
