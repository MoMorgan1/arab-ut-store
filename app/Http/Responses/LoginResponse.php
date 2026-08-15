<?php

namespace App\Http\Responses;

use App\Account\AccountOverviewUrl;
use App\Models\User;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

final class LoginResponse implements LoginResponseContract
{
    public function __construct(private readonly AccountOverviewUrl $accountOverviewUrl) {}

    public function toResponse($request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended($this->accountOverviewUrl->for($user));
    }
}
