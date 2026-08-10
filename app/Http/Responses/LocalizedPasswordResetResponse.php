<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;

class LocalizedPasswordResetResponse implements PasswordResetResponseContract
{
    public function __construct(private readonly string $status) {}

    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => trans($this->status)]);
        }

        $localized = $request->route('locale') === 'en';
        $loginUrl = $localized
            ? route('localized.login', ['locale' => 'en'], absolute: false)
            : route('login', absolute: false);

        return redirect($loginUrl)->with('status', trans($this->status));
    }
}
