<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The single invitation to review a finished order.
 *
 * Queued after commit and delayed an hour (owner decision, 2026-09-02): the
 * customer has had time to see the result before being asked what they think of
 * it. `orders.review_invited_at` keeps it to one per order across the
 * transaction's retries.
 */
final class ReviewInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Order $order)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->order->locale === 'en' ? 'en' : 'ar';
        $orderUrl = rtrim((string) config('app.url'), '/')
            .($locale === 'en' ? '/en' : '')
            .'/my-account/orders/'.$this->order->public_id;

        $this->locale($locale);

        return (new MailMessage)
            ->subject(trans(
                'mail.review_invite_subject',
                ['number' => $this->order->order_number],
                $locale,
            ))
            ->markdown('mail.review-invite', [
                'locale' => $locale,
                'number' => (string) $this->order->order_number,
                'orderUrl' => $orderUrl,
            ]);
    }
}
