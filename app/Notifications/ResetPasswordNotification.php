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
 * reset URL is built from the recipient's own locale rather than the ambient
 * one: this notification is queued, and on a worker app()->getLocale() is
 * APP_LOCALE, not the customer's -- which would hand an English recipient a
 * link to the Arabic RTL reset page. VerifyEmailNotification derives its URL
 * the same way.
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
        $resetUrl = $this->resetUrlFor($notifiable);
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

    private function resetUrlFor(User $notifiable): string
    {
        $localized = $this->messageLocale === 'en';

        return url(route(
            $localized ? 'localized.password.reset' : 'password.reset',
            [
                ...($localized ? ['locale' => 'en'] : []),
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ],
            absolute: false,
        ));
    }
}
