<?php

namespace App\Admin\Actions;

use App\Actions\Checkout\ReleaseOrderWalletFunds;
use App\Admin\Audit\StaffAuditEvent;
use App\Admin\Support\OrderStatusTransitionRules;
use App\Enums\AdminPermission;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Exceptions\AdminOrderStatusConflict;
use App\Loyalty\Actions\AccrueOrderCashback;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransitionAdminOrder
{
    public function __construct(
        private readonly OrderStatusTransitionRules $rules,
        private readonly RecordStaffAudit $recordStaffAudit,
        private readonly AccrueOrderCashback $accrueOrderCashback,
        private readonly ReleaseOrderWalletFunds $releaseOrderWalletFunds,
    ) {}

    public function execute(
        User $actor,
        string $orderPublicId,
        OrderStatus $targetStatus,
        OrderStatus $expectedStatus,
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

        return DB::transaction(function () use ($actor, $orderPublicId, $targetStatus, $expectedStatus): Order {
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

            if ($targetStatus === OrderStatus::Completed) {
                $order->completed_at = now();
            } elseif ($targetStatus === OrderStatus::Cancelled) {
                $order->cancelled_at = now();

                // The wallet is debited at placement, and a fully wallet-paid
                // order reaches Received without any Paylink payment - so
                // cancelling here would destroy the whole amount, and
                // RefundPaylinkOrder cannot recover it (it refuses cancelled
                // orders and needs a captured gateway payment).
                $this->releaseOrderWalletFunds->execute($order, 'admin_cancelled');
            }

            $order->save();

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'order_item_id' => null,
                'actor_user_id' => $actor->id,
                'status' => OrderStatusHistoryStatus::from($targetStatus->value),
                'note_ar' => null,
                'note_en' => null,
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
                    ],
                    ipAddress: request()->ip(),
                ),
            );

            return $order;
        }, attempts: 3);
    }
}
