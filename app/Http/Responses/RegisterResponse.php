<?php

namespace App\Http\Responses;

use App\Account\AccountOverviewUrl;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

final class RegisterResponse implements RegisterResponseContract
{
    public function __construct(private readonly AccountOverviewUrl $accountOverviewUrl) {}

    public function toResponse($request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return $request->wantsJson()
            ? new JsonResponse('', 201)
            : redirect()->intended($this->accountOverviewUrl->for($user));
    }
}
