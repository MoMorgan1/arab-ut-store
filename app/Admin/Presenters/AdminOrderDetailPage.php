<?php

namespace App\Admin\Presenters;

use App\Admin\Support\OrderStatusTransitionRules;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StaffAuditLog;
use App\Models\User;

final readonly class AdminOrderDetailPage
{
    public function __construct(
        private AdminShell $shell,
        private AdminOrderDetail $detailPresenter,
        private OrderStatusTransitionRules $rules,
    ) {}

    /**
     * @param  list<StaffAuditLog>|null  $auditLogs
     * @return array<string, mixed>
     */
    public function for(
        User $actor,
        string $locale,
        Order $order,
        ?array $auditLogs,
    ): array {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $allowedTargets = array_map(
            fn (OrderStatus $status): string => $status->value,
            $this->rules->allowedTargets($order->status),
        );

        /** @var Payment|null $payment */
        $payment = $order->relationLoaded('payments')
            ? $order->payments->where('provider', 'paylink')->sortByDesc('id')->first()
            : $order->payments()->where('provider', 'paylink')->latest('id')->first();

        $amountMinor = $payment instanceof Payment ? (string) $payment->captured_halalah : '0';
        $currency = $payment instanceof Payment ? (string) $payment->currency : (string) $order->currency;

        $eligible = $payment instanceof Payment
            && ! in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true)
            && $payment->status === PaymentStatus::Paid
            && $payment->currency === 'SAR'
            && $payment->captured_halalah > 0
            && $payment->refunded_halalah === 0
            && $payment->captured_halalah === $order->total_halalah
            && $this->hasNoRefundsForPayment($order, $payment);

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'order' => $this->detailPresenter->present($order, $locale, $auditLogs),
            'allowedTransitions' => $allowedTargets,
            'transitionUrl' => route($prefix.'orders.transitions.store', ['publicId' => (string) $order->public_id], absolute: false),
            'revealUrlTemplate' => route($prefix.'orders.items.reveal', ['publicId' => (string) $order->public_id, 'itemPublicId' => '__ITEM_ID__'], absolute: false),
            'refund' => [
                'eligible' => $eligible,
                'amountMinor' => $amountMinor,
                'currency' => $currency,
            ],
            'refundUrl' => route($prefix.'orders.paylink-refund', ['order' => (string) $order->public_id], absolute: false),
        ];
    }

    private function hasNoRefundsForPayment(Order $order, Payment $payment): bool
    {
        $idempotencyKey = 'paylink:'.hash('sha256', $order->id.'|'.$payment->id);

        if ($order->relationLoaded('refunds')) {
            return $order->refunds->where('payment_id', $payment->id)->isEmpty()
                && $order->refunds->where('idempotency_key', $idempotencyKey)->isEmpty();
        }

        return ! Refund::query()
            ->where('payment_id', $payment->id)
            ->orWhere('idempotency_key', $idempotencyKey)
            ->exists();
    }
}
