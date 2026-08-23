<?php

namespace App\Auth;

use App\Models\TwoFactorTrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Lets a browser that has already passed the TOTP challenge skip it on later
 * logins for a bounded window, so signing out and back in does not re-challenge
 * the same device.
 *
 * The cookie carries a random token; only its SHA-256 is stored, so the table
 * is not replayable. Trust is bound to one user: a cookie minted for one
 * account never satisfies the challenge for another.
 */
final class TrustedDeviceRegistry
{
    public const COOKIE = 'two_factor_device';

    public const LIFETIME_DAYS = 30;

    /**
     * Issues a fresh token for this browser. Called only after a TOTP code or a
     * recovery code has actually been accepted.
     */
    public function remember(User $user, Request $request): SymfonyCookie
    {
        $token = Str::random(64);

        $user->trustedDevices()->create([
            'token_hash' => $this->hash($token),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return Cookie::make(
            name: self::COOKIE,
            value: $token,
            minutes: self::LIFETIME_DAYS * 24 * 60,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /**
     * True when this browser holds an unexpired token belonging to this user.
     * Touches last_used_at so the trusted-device list shows real activity, but
     * deliberately does not extend expires_at — 30 days means 30 days from the
     * challenge, not a window that renews itself forever.
     */
    public function trusts(User $user, Request $request): bool
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return false;
        }

        $device = $user->trustedDevices()
            ->where('token_hash', $this->hash($token))
            ->where('expires_at', '>', now())
            ->first();

        if (! $device instanceof TwoFactorTrustedDevice) {
            return false;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    /** Revokes every device for this user. Returns how many were dropped. */
    public function forgetAll(User $user): int
    {
        return $user->trustedDevices()->delete();
    }

    /** @return int the number of expired rows removed */
    public function prune(): int
    {
        return TwoFactorTrustedDevice::query()
            ->where('expires_at', '<=', now())
            ->delete();
    }

    public function activeCount(User $user): int
    {
        return $user->trustedDevices()->where('expires_at', '>', now())->count();
    }

    /** Clears the cookie from this browser without touching other devices. */
    public function forgetCookie(): SymfonyCookie
    {
        return Cookie::forget(self::COOKIE);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
