<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The six-digit code that opens an account with no password.
 *
 * Queued, like the verification mail: sending inside the login request holds
 * the response open for an SMTP round trip, which is long enough to time the
 * sign-in out entirely when the mail host is slow.
 *
 * No link and no button. This mail is read on a phone while the code screen is
 * open on the same phone, and a link would only invite a second tab.
 *
 * Encrypted, because queued means written down. The code travels inside the
 * serialized job, and the database queue keeps that payload in `jobs` until a
 * worker takes it and in `failed_jobs` for good if the mail host refuses it -
 * in plain text, beside the hash that exists so the code is never stored. The
 * store hashes it on one side and would have printed it on the other.
 */
final class EmailLoginCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly string $messageLocale,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        if ($this->messageLocale === 'ar') {
            return (new MailMessage)
                ->subject('رمز الدخول إلى عرب التيميت')
                ->line('رمز الدخول الخاص بك:')
                ->line($this->code)
                ->line('الرمز صالح عشر دقائق.')
                ->line('إذا لم تطلب الدخول، تجاهل هذه الرسالة ولن يحدث شيء.');
        }

        return (new MailMessage)
            ->subject('Your Arab UT sign-in code')
            ->line('Your sign-in code:')
            ->line($this->code)
            ->line('The code is valid for ten minutes.')
            ->line('If you did not ask to sign in, ignore this email and nothing happens.');
    }
}
