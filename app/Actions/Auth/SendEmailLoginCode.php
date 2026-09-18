<?php

namespace App\Actions\Auth;

use App\Models\EmailLoginCode;
use App\Models\User;
use App\Notifications\EmailLoginCodeNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        // One issue at a time per address. Without the lock two requests can
        // both read "no recent code" and both write one, which is how a cap
        // meant to bound issuance stops bounding it.
        $lock = Cache::lock('email-login-code:issue:'.sha1($email), 10);

        if (! $lock->get()) {
            return false;
        }

        try {
            $recent = EmailLoginCode::query()
                ->where('email', $email)
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->where('created_at', '>=', now()->subMinute())
                ->exists();

            if ($recent) {
                return false;
            }

            $code = (string) random_int(100000, 999999);

            DB::transaction(function () use ($code, $email, $user): void {
                // A new code retires every older one. Otherwise the newest is
                // merely the one the reader picks first: once it is used and
                // stamped, the query falls back to the previous row and a code
                // the customer replaced minutes ago opens the account again.
                EmailLoginCode::query()
                    ->where('email', $email)
                    ->whereNull('verified_at')
                    ->where('expires_at', '>', now())
                    ->update(['expires_at' => now(), 'updated_at' => now()]);

                EmailLoginCode::create([
                    'user_id' => $user->id,
                    'email' => $email,
                    'code_hash' => Hash::make($code),
                    'attempts' => 0,
                    'expires_at' => now()->addMinutes(10),
                    'verified_at' => null,
                ]);
            });
        } finally {
            $lock->release();
        }

        // Queued, like the other account mail: an SMTP round trip inside the
        // login request is how a slow mail host becomes a broken sign-in page.
        // The row is written first, so a queue that runs late still delivers a
        // code the customer can use.
        $user->notify(new EmailLoginCodeNotification($code, $locale));

        return true;
    }
}
