<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
use App\Payments\PaymentMethodLabel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The owner's heads-up that a customer has paid: v14 sent it on WhatsApp
 * ("WA: Manual Order Alert"); the store sends it by mail to the address in
 * `store.order_alerts.email`.
 *
 * Queued after commit for the same reason the customer receipt is: a mail
 * server must never be able to fail a checkout that has taken the money.
 * Staff copy, so the dialect rule for customer screens does not apply, but it
 * stays short - the number, who, what, how much, and a link to the order.
 */
final class NewPaidOrderAlert extends Notification implements ShouldQueue
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
        $order = $this->order->loadMissing(['items', 'payments', 'user']);
        $number = (string) $order->order_number;
        $total = $this->money((int) $order->total_halalah);
        $customer = trim((string) $order->user->name);
        $method = PaymentMethodLabel::for($order->payments->sortByDesc('id')->first());

        $mail = (new MailMessage)
            ->subject("طلب جديد مدفوع {$number} - {$total}")
            ->greeting("طلب جديد: {$number}")
            ->line('العميل: '.($customer === '' ? '-' : $customer));

        foreach ($order->items as $item) {
            $mail->line('- '.$this->itemLine($item));
        }

        $mail->line("الإجمالي المدفوع: {$total}");

        if ($method !== null) {
            $mail->line('طريقة الدفع: '.(string) trans("payments.method_{$method}", [], 'ar'));
        }

        return $mail
            ->action('افتح الطلب', rtrim((string) config('app.url'), '/').'/admin/orders/'.$number)
            ->salutation('متجر عرب التيميت');
    }

    private function itemLine(OrderItem $item): string
    {
        $name = trim((string) $item->name_ar);
        $quantity = (int) $item->quantity;

        $line = ($name === '' ? $item->service_type->value : $name)." ({$item->platform->value})";

        if ($quantity > 1) {
            $line .= " × {$quantity}";
        }

        return $line.' - '.$this->money((int) $item->total_halalah);
    }

    /** Halalah are integers everywhere; they must not become floats on the way out. */
    private function money(int $halalah): string
    {
        return intdiv($halalah, 100).'.'.str_pad((string) ($halalah % 100), 2, '0', STR_PAD_LEFT).' ر.س';
    }
}
