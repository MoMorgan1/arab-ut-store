<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PendingEmailChangeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $verificationUrl,
        private readonly string $messageLocale,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->messageLocale === 'ar') {
            return (new MailMessage)
                ->subject('تأكيد بريدك الجديد في عرب التيميت')
                ->line('استخدم الزر التالي لتأكيد بريدك الإلكتروني الجديد. سيظل بريدك الحالي فعالًا حتى يتم التأكيد.')
                ->action('تأكيد البريد الجديد', $this->verificationUrl)
                ->line('إذا لم تطلب هذا التغيير، تجاهل الرسالة وتواصل مع الدعم.');
        }

        return (new MailMessage)
            ->subject('Confirm your new Arab UT email')
            ->line('Use the button below to confirm your new email address. Your current email stays active until confirmation.')
            ->action('Confirm new email', $this->verificationUrl)
            ->line('If you did not request this change, ignore this email and contact support.');
    }
}
