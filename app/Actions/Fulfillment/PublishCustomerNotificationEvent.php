<?php

namespace App\Actions\Fulfillment;

use App\Enums\NotificationStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Exceptions\CustomerNotificationIncomplete;
use App\Fulfillment\Notifications\CustomerNotificationCatalog;
use App\Fulfillment\Outbox\SignedOutboxDelivery;
use App\Models\IntegrationEvent;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends one queued customer notification to the n8n webhook.
 *
 * The stored rows name the message; the wire body adds what only the send
 * moment knows - the current phone number, the tracking link and the
 * catalogue wording - composed by `ComposeCustomerNotification`, so the
 * tables it came from never held any of them.
 *
 * Inert until the owner turns it on: with no webhook configured the row is
 * not even claimed. No attempt burned, no backoff, no alarming log line -
 * merging the queue writers is safe because of it.
 */
final class PublishCustomerNotificationEvent
{
    public function __construct(
        private readonly ComposeCustomerNotification $compose,
        private readonly SignedOutboxDelivery $delivery,
    ) {}

    public function execute(IntegrationEvent $event): bool
    {
        if ($event->fresh()?->status === 'processed') {
            return true;
        }

        if ($event->event_type !== CustomerNotificationCatalog::EVENT_TYPE) {
            return false;
        }

        if (! $this->delivery->isConfigured('customer_notify')) {
            return false;
        }

        $notification = NotificationDelivery::query()
            ->where('integration_event_id', $event->id)
            ->first();

        if (! $notification instanceof NotificationDelivery) {
            if (! $this->delivery->claim($event, CustomerNotificationCatalog::EVENT_TYPE)) {
                return $event->fresh()?->status === 'processed';
            }

            $this->delivery->release($event->refresh(), 'notification_missing');

            return false;
        }

        // One send per subject at a time. A held lock is refused rather than
        // queued behind: the row stays pending and the next tick retries.
        // The lock is taken before the claim so a refusal burns nothing.
        $lock = Cache::lock('customer-notify:'.$this->subjectKey($notification), 60);

        if (! $lock->get()) {
            return false;
        }

        try {
            if (! $this->delivery->claim($event, CustomerNotificationCatalog::EVENT_TYPE)) {
                return $event->fresh()?->status === 'processed';
            }

            $event->refresh();
            $notification->refresh();

            if ($notification->status !== NotificationStatus::Queued) {
                // A crash between the two marks left the event behind its
                // notification. The message already went (or was deliberately
                // not sent); finishing the event is the truthful remainder.
                $this->delivery->markProcessed($event);

                return true;
            }

            if (! $this->isCurrent($notification)) {
                // The hold cleared while the message waited - recovered,
                // completed, cancelled, refunded under it. Sending "your
                // order stopped" now would be telling them what is no longer
                // true, so the message expires instead of sending.
                $notification->forceFill([
                    'status' => NotificationStatus::Expired,
                    'last_error' => 'hold_no_longer_current',
                    'updated_at' => now(),
                ])->save();

                Log::info('Customer notification expired before sending; the hold is no longer current.', [
                    'notification_public_id' => (string) $notification->public_id,
                    'order_number' => $notification->payload['order_number'] ?? null,
                    'template' => $notification->template_key,
                ]);

                $this->delivery->markProcessed($event);

                return true;
            }

            try {
                $composed = $this->compose->execute($notification);
            } catch (CustomerNotificationIncomplete $incomplete) {
                Log::warning('Customer notification could not be composed; the event will be retried.', [
                    'notification_public_id' => (string) $notification->public_id,
                    'order_number' => $notification->payload['order_number'] ?? null,
                    'template' => $notification->template_key,
                    'recipient_masked' => $notification->recipient_masked,
                    'reason' => $incomplete->reason,
                ]);

                $notification->forceFill(['last_error' => $incomplete->reason, 'updated_at' => now()])->save();
                $this->delivery->release($event, $incomplete->reason);

                return false;
            }

            $acknowledged = $this->delivery->deliver($event, 'customer_notify', CustomerNotificationCatalog::SCHEMA_VERSION, [
                'notification_public_id' => (string) $notification->public_id,
                'order_number' => $notification->payload['order_number'] ?? null,
                'order_item_public_id' => $notification->payload['order_item_public_id'] ?? null,
                'template' => $notification->template_key,
                'locale' => $notification->locale,
                'idempotency_key' => $notification->idempotency_key,
                'to' => $composed['to'],
                'body' => $composed['body'],
            ]);

            if (! $acknowledged) {
                $notification->forceFill(['last_error' => 'delivery_failed', 'updated_at' => now()])->save();
                $this->delivery->release($event, 'delivery_failed');

                return false;
            }

            $notification->forceFill([
                'status' => NotificationStatus::Sent,
                'sent_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ])->save();

            $this->delivery->markProcessed($event);

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the message is still true of the order. A notification says
     * what its transition saw; if the order has moved on meanwhile, sending
     * it would tell the customer something that is no longer so.
     */
    private function isCurrent(NotificationDelivery $notification): bool
    {
        $order = Order::query()->whereKey($notification->order_id)->first();

        if (! $order instanceof Order) {
            return false;
        }

        if (in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Refunded], true)) {
            return ($notification->template_key === CustomerNotificationCatalog::TEMPLATE_ORDER_CANCELLED && $order->status === OrderStatus::Cancelled)
                || ($notification->template_key === CustomerNotificationCatalog::TEMPLATE_ORDER_REFUNDED && $order->status === OrderStatus::Refunded);
        }

        if ($notification->template_key === CustomerNotificationCatalog::TEMPLATE_ORDER_CANCELLED
            || $notification->template_key === CustomerNotificationCatalog::TEMPLATE_ORDER_REFUNDED) {
            return false;
        }

        if ($notification->order_item_id === null) {
            return false;
        }

        $item = OrderItem::query()->whereKey($notification->order_item_id)->first();

        return $item instanceof OrderItem && $item->status === OrderItemStatus::WaitingForCustomer;
    }

    private function subjectKey(NotificationDelivery $notification): string
    {
        $payload = $notification->payload;

        if (is_array($payload)) {
            $itemPublicId = $payload['order_item_public_id'] ?? null;

            if (is_string($itemPublicId) && $itemPublicId !== '') {
                return "item:{$itemPublicId}";
            }

            $orderPublicId = $payload['order_public_id'] ?? null;

            if (is_string($orderPublicId) && $orderPublicId !== '') {
                return "order:{$orderPublicId}";
            }
        }

        return "delivery:{$notification->id}";
    }
}
