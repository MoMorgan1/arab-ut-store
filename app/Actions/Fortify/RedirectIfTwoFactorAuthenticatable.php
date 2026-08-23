<?php

namespace App\Actions\Fortify;

use App\Auth\TrustedDeviceRegistry;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Skips the TOTP challenge for a browser that already passed it within the
 * trusted-device window.
 *
 * `handle()` restates the parent's branching rather than delegating to it: the
 * parent begins by validating credentials, so calling it after our own check
 * would verify the password hash twice on every single login.
 */
final class RedirectIfTwoFactorAuthenticatable extends FortifyRedirectIfTwoFactorAuthenticatable
{
    public function __construct(
        StatefulGuard $guard,
        LoginRateLimiter $limiter,
        private readonly TrustedDeviceRegistry $trustedDevices,
    ) {
        parent::__construct($guard, $limiter);
    }

    /**
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if (! $this->isTwoFactorAuthenticatable($user)) {
            return $next($request);
        }

        // The user still holds a confirmed TOTP secret; this only says the
        // challenge has already been satisfied from this browser recently.
        if ($user instanceof User && $this->trustedDevices->trusts($user, $request)) {
            return $next($request);
        }

        return $this->twoFactorChallengeResponse($request, $user);
    }

    private function isTwoFactorAuthenticatable(mixed $user): bool
    {
        if ($user === null || ! $user->two_factor_secret) {
            return false;
        }

        if (! in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user), true)) {
            return false;
        }

        return ! Fortify::confirmsTwoFactorAuthentication()
            || $user->two_factor_confirmed_at !== null;
    }
}
