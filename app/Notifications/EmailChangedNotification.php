<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailChangedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $messageLocale) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->messageLocale === 'ar') {
            return (new MailMessage)
                ->subject('تم تغيير بريد حسابك في عرب التيميت')
                ->line('تم تأكيد بريد إلكتروني جديد لحسابك.')
                ->line('إذا لم تقم بهذا التغيير، تواصل مع الدعم فورًا.');
        }

        return (new MailMessage)
            ->subject('Your Arab UT account email was changed')
            ->line('A new email address has been confirmed for your account.')
            ->line('If you did not make this change, contact support immediately.');
    }
}
