<?php

namespace App\Account\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class VerifySensitiveIdentityAction
{
    public function execute(User $user, Request $request, ?string $currentPassword): void
    {
        $passwordHash = $user->getAttribute('password');

        if (is_string($passwordHash) && $passwordHash !== '') {
            if (! is_string($currentPassword) || ! Hash::check($currentPassword, $passwordHash)) {
                throw ValidationException::withMessages([
                    'current_password' => trans('auth.password'),
                ]);
            }

            return;
        }

        $confirmedAt = $request->session()->get('auth.identity_confirmed_at');

        if (! is_int($confirmedAt) || $confirmedAt < now()->subMinutes(10)->timestamp) {
            throw new AuthorizationException('Recent identity verification is required.');
        }
    }
}
