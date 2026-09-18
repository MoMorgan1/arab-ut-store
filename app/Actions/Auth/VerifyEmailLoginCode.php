<?php

namespace App\Actions\Auth;

use App\Models\EmailLoginCode;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class VerifyEmailLoginCode
{
    /**
     * Checks the code and returns whose account it opens.
     *
     * A correct code proves the customer reads the address on the account, so
     * it also finishes the verification the Salla import never did - the same
     * proof a verification link would have carried, arriving by a different
     * road. Both writes are one transaction: an account let in but still
     * marked unverified would be locked out of password recovery all over
     * again.
     */
    public function execute(string $email, string $code): User
    {
        $email = mb_strtolower(trim($email));

        $user = DB::transaction(function () use ($code, $email): ?User {
            $pending = EmailLoginCode::query()
                ->where('email', $email)
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($pending === null || $pending->attempts >= 5) {
                return null;
            }

            $pending->increment('attempts');

            if (! Hash::check($code, $pending->code_hash)) {
                return null;
            }

            $user = $pending->user()->lockForUpdate()->first();

            if (! $user instanceof User || ! $user->is_active) {
                return null;
            }

            // The account must still be the one this code was issued for, and
            // must still be the kind of account a code opens. A code is bound
            // to a user id, and three things can make that binding a lie
            // between issuing and using it:
            //
            // - The customer changed their email. Whoever reads the old
            //   mailbox held a code for an address the account no longer has,
            //   and it must not let them in.
            // - The customer set a password. The account has a door of its own
            //   now, and codes are not it.
            // - Google claimed the account. That path nulls the password on
            //   purpose to evict pre-registered credentials, so the password
            //   check alone would still pass - the social link is what says
            //   the owner arrived by another road.
            if (mb_strtolower(trim((string) $user->email)) !== $email
                || $user->password !== null
                || $user->socialAccounts()->exists()) {
                return null;
            }

            $pending->forceFill(['verified_at' => now()])->save();

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        }, attempts: 3);

        if ($user === null) {
            throw new DomainException('The email login code is invalid or expired.');
        }

        return $user;
    }
}
