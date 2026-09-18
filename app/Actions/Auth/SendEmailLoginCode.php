<?php

namespace App\Actions\Auth;

use App\Models\EmailLoginCode;
use App\Models\User;
use App\Notifications\EmailLoginCodeNotification;
use Illuminate\Support\Facades\Hash;

final readonly class SendEmailLoginCode
{
    /**
     * Sends a six-digit code to an account that has no password to check.
     *
     * The caller decides who is eligible; this decides nothing except whether
     * the same address already asked within the minute. That split matters,
     * because eligibility here is a customer-visible fact - an account with no
     * password is told so - while a rate limit is not.
     *
     * Returns false when nothing was sent, which the caller reads as "say
     * nothing new": the previous code is still live and still in their inbox.
     */
    public function execute(User $user, string $locale): bool
    {
        $email = mb_strtolower(trim((string) $user->email));

        if ($email === '') {
            return false;
        }

        $recent = EmailLoginCode::query()
            ->where('email', $email)
            ->whereNull('verified_at')
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($recent) {
            return false;
        }

        $code = (string) random_int(100000, 999999);

        EmailLoginCode::create([
            'user_id' => $user->id,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'verified_at' => null,
        ]);

        // Queued, like the other account mail: an SMTP round trip inside the
        // login request is how a slow mail host becomes a broken sign-in page.
        // The row is written first, so a queue that runs late still delivers a
        // code the customer can use.
        $user->notify(new EmailLoginCodeNotification($code, $locale));

        return true;
    }
}
