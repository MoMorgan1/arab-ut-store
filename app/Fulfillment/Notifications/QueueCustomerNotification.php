<?php

namespace App\Fulfillment\Notifications;

use App\Enums\NotificationStatus;
use App\Enums\OrderHoldReason;
use App\Models\IntegrationEvent;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Records one owed WhatsApp message and its outbox event.
 *
 * Must be called inside the transition's own transaction - the supplier
 * reconciliation, the admin transition, the refund completion - so the row
 * commits with the status move or not at all. Writes that belong together
 * are one write: the notification row and its outbox event are created
 * together, and the publisher only ever sends what both agree is owed.
 *
 * Both rows carry identifiers only. The phone number, the tracking link and
 * the message body are composed at send time
 * (`ComposeCustomerNotification`), so a queued message carries a correction
 * made after it was queued, and neither stored payload ever holds the full
 * number: `recipient_masked` exists for exactly that reason.
 */
final class QueueCustomerNotification
{
    /**
     * One row for an item that stopped on the customer.
     *
     * @param  int  $historyId  the status-history row this transition wrote.
     *                          Together with the item and the template it is
     *                          what makes a repeat a replay: the same
     *                          transition attempted twice carries the same
     *                          key, while a genuine recurrence after recovery
     *                          writes a new history row and earns a new key.
     */
    public function forItem(
        Order $order,
        OrderItem $item,
        string $template,
        int $historyId,
        string $locale,
        OrderHoldReason $reason,
        string $source,
    ): NotificationDelivery {
        return $this->queue($order, $item, $template, $historyId, $locale, $reason, $source);
    }

    /** One row for the whole order: cancellation and refund. */
    public function forOrder(Order $order, string $template, int $historyId, string $locale): NotificationDelivery
    {
        return $this->queue($order, null, $template, $historyId, $locale, null, 'order');
    }

    private function queue(
        Order $order,
        ?OrderItem $item,
        string $template,
        int $historyId,
        string $locale,
        ?OrderHoldReason $reason,
        string $source,
    ): NotificationDelivery {
        $subject = $item === null ? "order:{$order->id}" : "item:{$item->id}";
        $key = "customer-notify:{$subject}:{$template}:{$historyId}";

        // The replay guard. Callers only queue on a genuine move, and they
        // hold the order lock while deciding, so this read is the common
        // case rather than a race window - but the unique index below is the
        // backstop if two writers ever disagree.
        $existing = NotificationDelivery::query()->where('idempotency_key', $key)->first();

        if ($existing instanceof NotificationDelivery) {
            return $existing;
        }

        $locale = $locale === 'en' ? 'en' : 'ar';

        $event = IntegrationEvent::create([
            'event_id' => (string) Str::ulid(),
            'event_type' => CustomerNotificationCatalog::EVENT_TYPE,
            'aggregate_type' => 'order',
            'aggregate_id' => (string) $order->public_id,
            'schema_version' => CustomerNotificationCatalog::SCHEMA_VERSION,
            'payload' => [
                'order_public_id' => (string) $order->public_id,
                'order_number' => $order->order_number,
                'order_item_public_id' => $item === null ? null : (string) $item->public_id,
                'template' => $template,
                'locale' => $locale,
            ],
            'status' => 'pending',
            'idempotency_key' => $key,
            'attempts' => 0,
            'available_at' => now(),
        ]);

        try {
            return NotificationDelivery::create([
                'user_id' => $order->user_id,
                'order_id' => $order->id,
                'order_item_id' => $item?->id,
                'integration_event_id' => $event->id,
                'channel' => 'whatsapp',
                'template_key' => $template,
                'idempotency_key' => $key,
                'locale' => $locale,
                'status' => NotificationStatus::Queued,
                'recipient_masked' => self::maskRecipient($order->user?->phone),
                'payload' => [
                    'order_public_id' => (string) $order->public_id,
                    'order_number' => $order->order_number,
                    'order_item_public_id' => $item === null ? null : (string) $item->public_id,
                    'template' => $template,
                    // What the publisher needs to ask, at send time, whether
                    // this message is still true: which transition it was
                    // written for, what it says the hold is, and who decided.
                    // A supplier hold's reason lives on the job and can be
                    // re-read; an admin's lives in the transition and cannot,
                    // so the two are checked differently.
                    'history_id' => $historyId,
                    'reason' => $reason?->value,
                    'source' => $source,
                ],
                'available_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A second writer queued the same transition concurrently. The
            // row that won is the message owed; this attempt is a replay.
            return NotificationDelivery::query()->where('idempotency_key', $key)->firstOrFail();
        }
    }

    /**
     * What an operator may read: enough to find the row, never the number.
     * The full recipient travels only in the signed request to n8n.
     */
    public static function maskRecipient(?string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);

        if ($digits === '') {
            return 'unknown';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
