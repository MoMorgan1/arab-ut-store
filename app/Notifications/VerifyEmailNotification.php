<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Localized replacement for Laravel's English-only verification mail. The
 * signed link targets the storefront's verify-email routes, using the
 * locale-prefixed variant for English recipients.
 *
 * Queued, like the order receipt. Registration fires this, and sending it
 * inside the request holds the response open for an SMTP round trip before the
 * customer ever reaches their account -- long enough to time out entirely when
 * the mail host is unreachable.
 */
final class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $messageLocale) {}

    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrlFor($notifiable);

        if ($this->messageLocale === 'ar') {
            return $this->buildArabicMessage($verificationUrl);
        }

        return $this->buildEnglishMessage($verificationUrl);
    }

    private function buildArabicMessage(string $verificationUrl): MailMessage
    {
        return (new MailMessage)
            ->subject('وثّق بريد حسابك في عرب التيميت')
            ->line('استخدم الزر التالي لتوثيق بريدك الإلكتروني.')
            ->action('توثيق البريد الإلكتروني', $verificationUrl)
            ->line('إذا لم تنشئ هذا الحساب، تجاهل هذه الرسالة.');
    }

    private function buildEnglishMessage(string $verificationUrl): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your Arab UT account email')
            ->line('Use the button below to verify your email address.')
            ->action('Verify email address', $verificationUrl)
            ->line('If you did not create this account, ignore this email.');
    }

    private function verificationUrlFor(User $notifiable): string
    {
        $localized = $this->messageLocale === 'en';

        return URL::temporarySignedRoute(
            $localized ? 'localized.verification.verify' : 'verification.verify',
            now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
            [
                ...($localized ? ['locale' => 'en'] : []),
                'id' => $notifiable->getKey(),
                'hash' => sha1((string) $notifiable->getEmailForVerification()),
            ],
        );
    }
}
