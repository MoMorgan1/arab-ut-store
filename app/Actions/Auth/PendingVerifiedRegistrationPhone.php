<?php

namespace App\Actions\Auth;

use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Http\Request;

final class PendingVerifiedRegistrationPhone
{
    public const SESSION_KEY = 'auth.verified_registration_phone';

    /**
     * A verified phone is a one-time grant that only needs to survive the trip
     * to the registration form. It must not inherit the session lifetime, which
     * is measured in days.
     */
    public const TTL_MINUTES = 30;

    public function remember(Request $request, E164Phone $phone): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'phone' => $phone->value(),
            'verified_at' => now()->timestamp,
        ]);
    }

    public function current(Request $request): ?E164Phone
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if ($pending === null) {
            return null;
        }

        if (! is_array($pending) || ! is_string($pending['phone'] ?? null)) {
            $this->forget($request);

            return null;
        }

        $verifiedAt = $pending['verified_at'] ?? null;

        // A payload without verified_at predates this deploy, so it is treated
        // as stale rather than trusted forever.
        if (! is_int($verifiedAt) || $verifiedAt < now()->subMinutes(self::TTL_MINUTES)->getTimestamp()) {
            $this->forget($request);

            return null;
        }

        try {
            return E164Phone::from($pending['phone']);
        } catch (DomainException) {
            $this->forget($request);

            return null;
        }
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
