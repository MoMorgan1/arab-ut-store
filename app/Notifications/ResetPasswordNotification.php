<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;

/**
 * Localized replacement for Laravel's English-only password reset mail. The
 * reset URL continues through the configured ResetPassword::createUrlUsing()
 * callback, maintaining localized routes for English recipients.
 *
 * Queued, following the same contract as VerifyEmailNotification.
 */
final class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter]
        string $token,
        private readonly string $messageLocale = 'ar',
    ) {
        parent::__construct($token);
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $expireMinutes = (int) Config::get('auth.passwords.users.expire', 60);
        $resetUrl = $this->resetUrl($notifiable);
        $locale = $this->messageLocale;

        $this->locale($locale);

        return (new MailMessage)
            ->subject((string) trans('mail.reset_password_subject', [], $locale))
            ->line((string) trans('mail.reset_password_intro', [], $locale))
            ->action((string) trans('mail.reset_password_action', [], $locale), $resetUrl)
            ->line((string) trans('mail.reset_password_expire', ['count' => $expireMinutes], $locale))
            ->line((string) trans('mail.reset_password_no_action', [], $locale))
            ->salutation((string) trans('mail.reset_password_salutation', [], $locale));
    }
}
