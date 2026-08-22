<?php

namespace App\Admin\Presenters;

use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StaffAuditLog;
use App\Support\SafeOrderItemConfiguration;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class AdminOrderDetail
{
    /**
     * @param  list<StaffAuditLog>|null  $auditLogs
     * @return array{
     *     id: string,
     *     orderNumber: string,
     *     status: string,
     *     currency: string,
     *     placedAt: ?string,
     *     paidAt: ?string,
     *     completedAt: ?string,
     *     cancelledAt: ?string,
     *     customer: array{id: string, name: string, email: string, phone: ?string},
     *     money: array{
     *         subtotal: array{amountMinor: string, currency: string},
     *         discount: array{amountMinor: string, currency: string},
     *         wallet: array{amountMinor: string, currency: string},
     *         payment: array{amountMinor: string, currency: string},
     *         total: array{amountMinor: string, currency: string}
     *     },
     *     items: list<array{
     *         id: string,
     *         name: string,
     *         serviceType: string,
     *         platform: string,
     *         quantity: int,
     *         unitPrice: array{amountMinor: string, currency: string},
     *         subtotal: array{amountMinor: string, currency: string},
     *         discount: array{amountMinor: string, currency: string},
     *         total: array{amountMinor: string, currency: string},
     *         status: string,
     *         configuration: ?array<string, mixed>,
     *         hasSecret: bool,
     *         maskedSummary: ?array<string, mixed>,
     *         statusHistory: list<array{
     *             id: string,
     *             status: string,
     *             source: ?string,
     *             previousStatus: ?string,
     *             newStatus: ?string,
     *             createdAt: string,
     *             actor: ?array{name: string, role: string}
     *         }>
     *     }>,
     *     payments: list<array{
     *         id: string,
     *         status: string,
     *         currency: string,
     *         amount: array{amountMinor: string, currency: string},
     *         capturedAmount: array{amountMinor: string, currency: string},
     *         refundedAmount: array{amountMinor: string, currency: string},
     *         paidAt: ?string,
     *         createdAt: string
     *     }>,
     *     refunds: list<array{
     *         id: string,
     *         status: string,
     *         method: string,
     *         amount: array{amountMinor: string, currency: string},
     *         reason: ?string,
     *         completedAt: ?string,
     *         createdAt: string
     *     }>,
     *     discounts: list<array{
     *         id: string,
     *         type: string,
     *         label: string,
     *         amount: array{amountMinor: string, currency: string}
     *     }>,
     *     statusHistory: list<array{
     *         id: string,
     *         status: string,
     *         source: ?string,
     *         previousStatus: ?string,
     *         newStatus: ?string,
     *         createdAt: string,
     *         actor: ?array{name: string, role: string}
     *     }>,
     *     auditContext: ?list<array{
     *         id: string,
     *         action: string,
     *         actor: ?array{name: string, role: string},
     *         createdAt: string
     *     }>
     * }
     */
    public function present(Order $order, string $locale, ?array $auditLogs = null): array
    {
        $currency = (string) $order->getAttribute('currency');
        $customer = $order->user;

        return [
            'id' => (string) $order->public_id,
            'orderNumber' => (string) $order->order_number,
            'status' => $order->status->value,
            'currency' => $currency,
            'placedAt' => self::isoDate($order->getAttribute('placed_at')),
            'paidAt' => self::isoDate($order->getAttribute('paid_at')),
            'completedAt' => self::isoDate($order->getAttribute('completed_at')),
            'cancelledAt' => self::isoDate($order->getAttribute('cancelled_at')),
            'customer' => [
                'id' => (string) $customer?->public_id,
                'name' => trim((string) $customer?->first_name.' '.(string) $customer?->last_name),
                'email' => (string) $customer?->email,
                'phone' => $customer?->phone !== null ? (string) $customer->phone : null,
            ],
            'money' => [
                'subtotal' => self::money($order->getAttribute('subtotal_halalah'), $currency),
                'discount' => self::money($order->getAttribute('discount_halalah'), $currency),
                'wallet' => self::money($order->getAttribute('wallet_halalah'), $currency),
                'payment' => self::money($order->getAttribute('payment_halalah'), $currency),
                'total' => self::money($order->getAttribute('total_halalah'), $currency),
            ],
            'items' => array_values($order->items->map(fn (OrderItem $item): array => [
                'id' => (string) $item->public_id,
                'name' => $locale === 'ar' ? (string) $item->name_ar : (string) $item->name_en,
                'serviceType' => $item->service_type->value,
                'platform' => $item->platform->value,
                'quantity' => (int) $item->quantity,
                'unitPrice' => self::money($item->getAttribute('unit_price_halalah'), $currency),
                'subtotal' => self::money($item->getAttribute('subtotal_halalah'), $currency),
                'discount' => self::money($item->getAttribute('discount_halalah'), $currency),
                'total' => self::money($item->getAttribute('total_halalah'), $currency),
                'status' => $item->status->value,
                'configuration' => $item->configuration === null
                    ? null
                    : SafeOrderItemConfiguration::project($item->configuration, $item->service_type),
                'hasSecret' => $item->secret !== null,
                'maskedSummary' => $item->secret?->masked_summary,
                'statusHistory' => self::historyList($item->statusHistory),
            ])->all()),
            'payments' => array_values($order->payments->map(fn (Payment $payment): array => [
                'id' => (string) $payment->public_id,
                'status' => (string) $payment->status->value,
                'currency' => (string) $payment->currency,
                'amount' => self::money($payment->getAttribute('amount_halalah'), (string) $payment->currency),
                'capturedAmount' => self::money($payment->getAttribute('captured_halalah'), (string) $payment->currency),
                'refundedAmount' => self::money($payment->getAttribute('refunded_halalah'), (string) $payment->currency),
                'paidAt' => self::isoDate($payment->getAttribute('paid_at')),
                'createdAt' => (string) self::isoDate($payment->created_at),
            ])->all()),
            'refunds' => array_values($order->refunds->map(fn (Refund $refund): array => [
                'id' => (string) $refund->public_id,
                'status' => (string) $refund->status,
                'method' => (string) $refund->method,
                'amount' => self::money($refund->amount_halalah, $currency),
                'reason' => $locale === 'ar' ? $refund->reason_ar : $refund->reason_en,
                'completedAt' => self::isoDate($refund->completed_at),
                'createdAt' => (string) self::isoDate($refund->created_at),
            ])->all()),
            'discounts' => array_values($order->discounts->map(fn (OrderDiscount $discount): array => [
                'id' => (string) $discount->public_id,
                'type' => (string) $discount->type,
                'label' => $locale === 'ar' ? (string) $discount->label_ar : (string) $discount->label_en,
                'amount' => self::money($discount->getAttribute('amount_halalah'), $currency),
            ])->all()),
            'statusHistory' => self::historyList($order->statusHistory),
            'auditContext' => $auditLogs !== null ? array_map(
                fn (StaffAuditLog $log): array => [
                    'id' => (string) $log->public_id,
                    'action' => (string) $log->action,
                    'actor' => $log->actor ? [
                        'name' => (string) $log->actor->name,
                        'role' => (string) $log->actor->role->value,
                    ] : null,
                    'createdAt' => $log->created_at->toIso8601String(),
                ],
                $auditLogs,
            ) : null,
        ];
    }

    /**
     * @param  Collection<int, OrderStatusHistory>  $histories
     * @return list<array{id: string, status: string, source: ?string, previousStatus: ?string, newStatus: ?string, createdAt: string, actor: ?array{name: string, role: string}}>
     */
    private static function historyList(Collection $histories): array
    {
        return array_values(array_map(
            fn (OrderStatusHistory $history): array => [
                'id' => (string) $history->public_id,
                ...self::historyEntry($history),
            ],
            $histories->all(),
        ));
    }

    /**
     * @return array{status: string, source: ?string, previousStatus: ?string, newStatus: ?string, createdAt: string, actor: ?array{name: string, role: string}}
     */
    private static function historyEntry(OrderStatusHistory $history): array
    {
        $metadata = $history->getAttribute('metadata');
        /** @var array<string, mixed>|null $metadataArray */
        $metadataArray = is_array($metadata) ? $metadata : null;
        $actor = $history->actor;

        return [
            'status' => self::enumValue($history->getAttribute('status')),
            'source' => isset($metadataArray['source']) ? (string) $metadataArray['source'] : null,
            'previousStatus' => isset($metadataArray['previous_status']) ? (string) $metadataArray['previous_status'] : null,
            'newStatus' => isset($metadataArray['new_status']) ? (string) $metadataArray['new_status'] : null,
            'createdAt' => (string) self::isoDate($history->getAttribute('created_at')),
            'actor' => $actor !== null ? [
                'name' => (string) $actor->name,
                'role' => (string) $actor->role->value,
            ] : null,
        ];
    }

    /**
     * @return array{amountMinor: string, currency: string}
     */
    private static function money(mixed $amountMinor, string $currency): array
    {
        return [
            'amountMinor' => (string) $amountMinor,
            'currency' => $currency,
        ];
    }

    private static function isoDate(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    private static function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
