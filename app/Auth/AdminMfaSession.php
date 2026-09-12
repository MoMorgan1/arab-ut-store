<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Owns the session marker that tells the admin gate the TOTP challenge has
 * already been satisfied. Two keys are written together: the Unix timestamp of
 * the grant and how it was earned.
 */
final class AdminMfaSession
{
    public const CONFIRMED_AT_KEY = 'auth.two_factor_confirmed_at';

    public const CONFIRMED_VIA_KEY = 'auth.two_factor_confirmed_via';

    public const VIA_CHALLENGE = 'challenge';

    public const VIA_DEVICE = 'device';

    /**
     * A session grant never outlives the trusted-device lifetime; matching
     * TrustedDeviceRegistry::LIFETIME_DAYS keeps the two grants from drifting.
     */
    public const MAX_AGE_DAYS = 30;

    /**
     * A device-backed grant is accepted without re-reading the device row for
     * this long. TrustedDeviceRegistry::trusts() touches last_used_at, so
     * re-checking on every admin request would be a database write per request.
     */
    public const DEVICE_RECHECK_MINUTES = 10;

    public function __construct(private readonly TrustedDeviceRegistry $trustedDevices) {}

    public function stampChallenge(Request $request): void
    {
        $request->session()->put([
            self::CONFIRMED_AT_KEY => now()->getTimestamp(),
            self::CONFIRMED_VIA_KEY => self::VIA_CHALLENGE,
        ]);
    }

    public function stampDevice(Request $request): void
    {
        $request->session()->put([
            self::CONFIRMED_AT_KEY => now()->getTimestamp(),
            self::CONFIRMED_VIA_KEY => self::VIA_DEVICE,
        ]);
    }

    public function forget(Request $request): void
    {
        $request->session()->forget([
            self::CONFIRMED_AT_KEY,
            self::CONFIRMED_VIA_KEY,
        ]);
    }

    public function satisfied(User $user, Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $confirmedAt = $request->session()->get(self::CONFIRMED_AT_KEY);
        $via = $request->session()->get(self::CONFIRMED_VIA_KEY, self::VIA_CHALLENGE);

        if (is_int($confirmedAt)) {
            $age = now()->getTimestamp() - $confirmedAt;
            $invalidatedAt = $user->mfa_invalidated_at?->getTimestamp();

            // A credential change stamps the account, and a marker minted before
            // that moment is dead wherever its session lives: this is how a
            // password reset reaches browsers the reset itself cannot clear.
            $invalidated = $invalidatedAt !== null && $confirmedAt < $invalidatedAt;

            if (! $invalidated && $age <= self::MAX_AGE_DAYS * 24 * 60 * 60) {
                // A challenge grant stands for its whole life, while a device grant
                // is only trusted briefly so a revocation can take effect quickly.
                if ($via !== self::VIA_DEVICE || $age <= self::DEVICE_RECHECK_MINUTES * 60) {
                    return true;
                }
            }
        }

        if ($this->trustedDevices->trusts($user, $request)) {
            $this->stampDevice($request);

            return true;
        }

        return false;
    }
}
