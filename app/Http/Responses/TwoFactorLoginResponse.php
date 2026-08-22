<?php

namespace App\Http\Responses;

use App\Account\AccountOverviewUrl;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

final class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(private readonly AccountOverviewUrl $accountOverviewUrl) {}

    public function toResponse($request): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        $target = $user !== null && in_array($user->role, [UserRole::Admin, UserRole::Staff], true)
            ? route('admin.overview')
            : ($user !== null ? $this->accountOverviewUrl->for($user) : '/');

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended($target);
    }
}
