<?php

namespace App\Actions\Fulfillment;

use App\Actions\Orders\IssueOrderTrackingLink;
use App\Exceptions\CustomerNotificationIncomplete;
use App\Models\NotificationDelivery;
use App\Models\Order;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * Composes what n8n sends for one queued customer notification.
 *
 * Everything fresh at send time: the recipient from the order's user as they
 * stand now, the tracking link (issued once and reused, so a resend never
 * mints a second token a message in flight does not reference), and the body
 * from the catalogue in the queued locale. A correction made after the row
 * was queued is therefore what the customer reads, and a wording fix ships
 * without requeueing anything.
 *
 * The full number belongs in the returned `to` and nowhere else: not in a
 * log line, not in either stored payload, and never in the exception.
 */
final class ComposeCustomerNotification
{
    public function __construct(
        private readonly IssueOrderTrackingLink $links,
    ) {}

    /**
     * @return array{to: string, body: string} `to` is international digits
     *                                         without the `+`, as Whapi takes it.
     */
    public function execute(NotificationDelivery $notification): array
    {
        $notification->loadMissing(['order.user', 'orderItem']);

        $order = $notification->order;

        if (! $order instanceof Order) {
            throw new CustomerNotificationIncomplete('order_missing', 'The notified order is gone.');
        }

        $to = self::normalizeRecipient($order->user?->phone);

        if ($to === null) {
            throw new CustomerNotificationIncomplete(
                'recipient_missing',
                'The customer has no usable phone number.',
            );
        }

        try {
            $link = $this->links->execute($order);
        } catch (Throwable) {
            throw new CustomerNotificationIncomplete('link_failed', 'The order tracking link could not be issued.');
        }

        $locale = $notification->locale === 'en' ? 'en' : 'ar';
        $key = "notifications.{$notification->template_key}";

        if (! Lang::has($key, $locale)) {
            throw new CustomerNotificationIncomplete('template_unknown', 'The queued template has no wording.');
        }

        $name = trim((string) ($order->user->first_name ?? ''));

        if ($name === '') {
            $name = $locale === 'en' ? 'there' : 'بك';
        } else {
            // The greeting takes the first word of the first name. A checkout
            // form that collected a full name should not produce a WhatsApp
            // message that reads like a letter from a bank.
            $name = (string) preg_replace('/\s.*\z/us', '', $name);
        }

        $body = trans($key, [
            'name' => $name,
            'order_number' => $order->order_number,
            'link' => $link,
        ], $locale);

        return ['to' => $to, 'body' => $body];
    }

    /**
     * International digits without the `+`. Returns null when there is
     * nothing a WhatsApp send could use - the publisher records the reason,
     * not the input.
     */
    public static function normalizeRecipient(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $phone);

        if (strlen($digits) < 9 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }
}
