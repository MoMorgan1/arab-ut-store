<?php

namespace App\Admin\Actions;

use App\Actions\Checkout\ReleaseOrderWalletFunds;
use App\Admin\Audit\StaffAuditEvent;
use App\Admin\Support\OrderStatusTransitionRules;
use App\Enums\AdminPermission;
use App\Enums\OrderHoldReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\AdminOrderStatusConflict;
use App\Loyalty\Actions\AccrueOrderCashback;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentManager;
use App\Support\OrderClosingNote;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class TransitionAdminOrder
{
    public function __construct(
        private readonly OrderStatusTransitionRules $rules,
        private readonly RecordStaffAudit $recordStaffAudit,
        private readonly AccrueOrderCashback $accrueOrderCashback,
        private readonly ReleaseOrderWalletFunds $releaseOrderWalletFunds,
        private readonly PaymentManager $payments,
    ) {}

    public function execute(
        User $actor,
        string $orderPublicId,
        OrderStatus $targetStatus,
        OrderStatus $expectedStatus,
        ?OrderHoldReason $reason = null,
        ?string $note = null,
    ): Order {
        if ($targetStatus === OrderStatus::Refunded) {
            throw ValidationException::withMessages([
                'target_status' => ['Manual transition to refunded is not allowed.'],
            ]);
        }

        if (! $actor->can(AdminPermission::OrdersUpdate->value)) {
            throw new AuthorizationException('This action requires orders.update permission.');
        }

        if ($targetStatus === OrderStatus::Cancelled && ! $actor->can(AdminPermission::OrdersCancel->value)) {
            throw new AuthorizationException('This action requires orders.cancel permission.');
        }

        return DB::transaction(function () use ($actor, $orderPublicId, $targetStatus, $expectedStatus, $reason, $note): Order {
            /** @var Order $order */
            $order = Order::query()
                ->where('public_id', $orderPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Collection<int, OrderItem> $items */
            $items = OrderItem::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($order->channel === 'salla_import') {
                throw ValidationException::withMessages([
                    'target_status' => ['Imported orders are read-only and cannot transition.'],
                ]);
            }

            if ($order->status !== $expectedStatus) {
                throw new AdminOrderStatusConflict((string) $order->public_id, $order->status->value);
            }

            $itemSourceStatuses = $this->rules->itemTargets($order->status, $targetStatus);

            if ($itemSourceStatuses === null) {
                throw ValidationException::withMessages([
                    'target_status' => ['Illegal order status transition.'],
                ]);
            }

            $previousStatus = $order->status;
            $order->status = $targetStatus;
            $releasedHalalah = 0;

            if ($targetStatus === OrderStatus::Completed) {
                $order->completed_at = now();
            } elseif ($targetStatus === OrderStatus::Cancelled) {
                /** @var Collection<int, Payment> $pendingPayments */
                $pendingPayments = Payment::query()
                    ->where('order_id', $order->id)
                    ->where('status', PaymentStatus::Pending)
                    ->whereNotNull('provider_payment_id')
                    ->where('provider_payment_id', '!=', '')
                    ->lockForUpdate()
                    ->get();

                foreach ($pendingPayments as $pendingPayment) {
                    try {
                        $this->payments->gateway()->cancelInvoice((string) $pendingPayment->provider_payment_id);
                    } catch (Throwable) {
                        throw ValidationException::withMessages([
                            'target_status' => ['The payment invoice could not be cancelled with the provider.'],
                        ]);
                    }

                    $pendingPayment->forceFill(['status' => PaymentStatus::Cancelled])->save();
                }

                $order->cancelled_at = now();

                // The wallet is debited at placement, and a fully wallet-paid
                // order reaches Received without any Paylink payment - so
                // cancelling here would destroy the whole amount, and
                // RefundPaylinkOrder cannot recover it (it refuses cancelled
                // orders and needs a captured gateway payment).
                $releasedHalalah = $this->releaseOrderWalletFunds->execute($order, 'admin_cancelled');
            }

            $order->save();

            // An admin cancelling a wallet-funded order moves real money back.
            // Without this the customer reads "cancelled" and nothing else, and
            // has to work out for themselves that their balance is whole again.
            // Only the wallet half is named: the gateway half is not refunded on
            // this path at all, so claiming it would be worse than silence.
            $closingNote = $releasedHalalah > 0
                ? OrderClosingNote::refund(cardHalalah: 0, walletHalalah: $releasedHalalah)
                : null;

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'order_item_id' => null,
                'actor_user_id' => $actor->id,
                'status' => OrderStatusHistoryStatus::from($targetStatus->value),
                'note_ar' => self::composeNote($reason, $note, 'ar', $closingNote['note_ar'] ?? null),
                'note_en' => self::composeNote($reason, $note, 'en', $closingNote['note_en'] ?? null),
                'metadata' => [
                    'source' => 'admin',
                    'previous_status' => $previousStatus->value,
                    'new_status' => $targetStatus->value,
                ],
            ]);

            $propagatedItemCount = 0;
            $targetItemStatus = OrderItemStatus::from($targetStatus->value);

            foreach ($items as $item) {
                if (in_array($item->status, $itemSourceStatuses, true)) {
                    $previousItemStatus = $item->status;
                    $item->status = $targetItemStatus;
                    $item->save();
                    $propagatedItemCount++;

                    OrderStatusHistory::query()->create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'actor_user_id' => $actor->id,
                        'status' => OrderStatusHistoryStatus::from($targetItemStatus->value),
                        'note_ar' => null,
                        'note_en' => null,
                        'metadata' => [
                            'source' => 'admin',
                            'previous_status' => $previousItemStatus->value,
                            'new_status' => $targetItemStatus->value,
                        ],
                    ]);
                }
            }

            if ($targetStatus === OrderStatus::Completed) {
                $this->accrueOrderCashback->execute($order);
            }

            $this->recordStaffAudit->execute(
                actor: $actor,
                subject: $order,
                event: new StaffAuditEvent(
                    action: 'orders.status_changed',
                    metadata: [
                        'source' => 'admin',
                        'previous_status' => $previousStatus->value,
                        'new_status' => $targetStatus->value,
                        'order_public_id' => (string) $order->public_id,
                        'propagated_item_count' => $propagatedItemCount,
                        'reason' => $reason?->value,
                        'note_given' => $note !== null,
                    ],
                    ipAddress: request()->ip(),
                ),
            );

            return $order;
        }, attempts: 3);
    }

    /**
     * Freeze what the customer is told into the history row.
     *
     * The curated reason is resolved per locale now rather than at read time, so
     * a later wording change never rewrites a message a customer already read.
     */
    private static function composeNote(
        ?OrderHoldReason $reason,
        ?string $note,
        string $locale,
        ?string $closing = null,
    ): ?string {
        // Why it happened first, then where the money went - the order a person
        // would say it in.
        $parts = array_values(array_filter([
            $reason?->message($locale),
            $note,
            $closing,
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        return $parts === [] ? null : implode("\n\n", $parts);
    }
}
