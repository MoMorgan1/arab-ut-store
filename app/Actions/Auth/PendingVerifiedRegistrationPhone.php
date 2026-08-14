<?php

namespace App\Actions\Auth;

use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Http\Request;

final class PendingVerifiedRegistrationPhone
{
    public const SESSION_KEY = 'auth.verified_registration_phone';

    public function remember(Request $request, E164Phone $phone): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'phone' => $phone->value(),
        ]);
    }

    public function current(Request $request): ?E164Phone
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ! is_string($pending['phone'] ?? null)) {
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
