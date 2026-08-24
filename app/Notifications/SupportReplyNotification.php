<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SupportReplyNotification extends Notification
{
    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly User $staff,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $isEn = $locale === 'en';

        $subject = $isEn
            ? "New reply from Arab Ultimate team — Ticket {$this->ticket->ticket_number}"
            : "رد جديد من فريق عرب التيميت — تذكرة {$this->ticket->ticket_number}";

        // Notification::toMail is typed `object`; only a User carries a name.
        $customerName = $notifiable instanceof User ? $notifiable->name : '';

        $greeting = $isEn
            ? trim("Hi {$customerName}").','
            : trim("أهلًا {$customerName}").'،';

        $line1 = $isEn
            ? "{$this->staff->name} from the support team has replied to your ticket."
            : "قام {$this->staff->name} من فريق الدعم بالرد على تذكرتك.";

        $actionText = $isEn ? 'View conversation' : 'عرض المحادثة';
        $actionUrl = route('home');

        $line2 = $isEn
            ? 'You can continue your conversation directly on the store.'
            : 'يمكنك متابعة المحادثة والرد مباشرة عبر الموقع.';

        return (new MailMessage)
            ->subject($subject)
            ->greeting($greeting)
            ->line($line1)
            ->action($actionText, $actionUrl)
            ->line($line2);
    }

    private function resolveLocale(object $notifiable): string
    {
        if (isset($notifiable->preferred_locale) && is_string($notifiable->preferred_locale)) {
            return $notifiable->preferred_locale;
        }

        $conversation = $this->ticket->conversation;

        if ($conversation !== null) {
            return $conversation->locale;
        }

        return 'ar';
    }
}
