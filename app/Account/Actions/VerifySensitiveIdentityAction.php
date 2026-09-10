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

        if (! $this->recentlyConfirmed($request)) {
            throw new AuthorizationException('Recent identity verification is required.');
        }
    }

    public function recentlyConfirmed(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.identity_confirmed_at');

        return is_int($confirmedAt) && $confirmedAt >= now()->subMinutes(10)->timestamp;
    }
}
