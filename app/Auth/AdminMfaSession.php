<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Owns the session marker that tells the admin gate the TOTP challenge has
 * already been satisfied. Three keys are written together: the Unix timestamp
 * of the grant, how it was earned, and the account's revocation counter when it
 * was minted.
 */
final class AdminMfaSession
{
    public const CONFIRMED_AT_KEY = 'auth.two_factor_confirmed_at';

    public const CONFIRMED_VIA_KEY = 'auth.two_factor_confirmed_via';

    /**
     * The account's revocation counter at the moment of the grant. A credential
     * change bumps the counter, stranding every marker that carries an older
     * value wherever its session lives.
     */
    public const REVOCATION_KEY = 'auth.two_factor_revocation';

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
        $user = $request->user();

        // Callers only reach this after authenticating, and the marker is only
        // meaningful against an account, so an anonymous request stamps nothing.
        if (! $user instanceof User) {
            return;
        }

        $this->stamp($request, $user, self::VIA_CHALLENGE);
    }

    public function stampDevice(User $user, Request $request): void
    {
        $this->stamp($request, $user, self::VIA_DEVICE);
    }

    public function forget(Request $request): void
    {
        $request->session()->forget([
            self::CONFIRMED_AT_KEY,
            self::CONFIRMED_VIA_KEY,
            self::REVOCATION_KEY,
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
            $markerRevocation = $request->session()->get(self::REVOCATION_KEY);

            // A credential change bumps the account's counter, and a marker
            // that records any other value was minted before that change and is
            // dead: this is how a password reset reaches browsers the reset
            // itself cannot clear. A marker with no counter reads as zero, the
            // value a never-revoked account has, so the bare marker
            // tests/TestCase.php seeds keeps working until the first revocation.
            $counterMatches = ($markerRevocation ?? 0) === $user->mfa_revocation;

            if ($counterMatches && $age <= self::MAX_AGE_DAYS * 24 * 60 * 60) {
                // A challenge grant stands for its whole life, while a device grant
                // is only trusted briefly so a revocation can take effect quickly.
                if ($via !== self::VIA_DEVICE || $age <= self::DEVICE_RECHECK_MINUTES * 60) {
                    return true;
                }
            }
        }

        if ($this->trustedDevices->trusts($user, $request)) {
            $this->stampDevice($user, $request);

            return true;
        }

        return false;
    }

    private function stamp(Request $request, User $user, string $via): void
    {
        $request->session()->put([
            self::CONFIRMED_AT_KEY => now()->getTimestamp(),
            self::CONFIRMED_VIA_KEY => $via,
            self::REVOCATION_KEY => $user->mfa_revocation,
        ]);
    }
}
