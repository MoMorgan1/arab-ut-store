<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

final class LocalizedFailedTwoFactorLoginResponse implements FailedTwoFactorLoginResponseContract
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        $usesRecoveryCode = $request->filled('recovery_code');
        $key = $usesRecoveryCode ? 'recovery_code' : 'code';
        $translation = $usesRecoveryCode
            ? 'auth_ui.two_factor_challenge.invalid_recovery_code'
            : 'auth_ui.two_factor_challenge.invalid_code';
        $message = trans($translation);

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([$key => [$message]]);
        }

        return redirect()->route('two-factor.login')->withErrors([$key => $message]);
    }
}
