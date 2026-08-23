<?php

namespace App\Http\Responses;

use App\Account\AccountOverviewUrl;
use App\Auth\TrustedDeviceRegistry;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

final class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(
        private readonly AccountOverviewUrl $accountOverviewUrl,
        private readonly TrustedDeviceRegistry $trustedDevices,
    ) {}

    public function toResponse($request): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        $target = $user !== null && in_array($user->role, [UserRole::Admin, UserRole::Staff], true)
            ? route('admin.overview')
            : ($user !== null ? $this->accountOverviewUrl->for($user) : '/');

        $response = $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended($target);

        // Reaching this response means a TOTP or recovery code was accepted, so
        // this browser is trusted for the next 30 days and later logins skip the
        // challenge. Signing out does not clear it — that is the point.
        if ($user !== null) {
            $response->withCookie($this->trustedDevices->remember($user, $request));
        }

        return $response;
    }
}
