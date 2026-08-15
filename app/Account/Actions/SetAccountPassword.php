<?php

namespace App\Account\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final readonly class SetAccountPassword
{
    public function __construct(private VerifySensitiveIdentityAction $sensitiveIdentity) {}

    public function change(User $user, string $password): void
    {
        if (! $this->hasPassword($user)) {
            throw new AuthorizationException('Password setup must use trusted verification.');
        }

        $user->forceFill(['password' => $password])->save();
    }

    public function setup(User $user, Request $request, string $password): void
    {
        if ($this->hasPassword($user)) {
            throw new AuthorizationException('This account already has a password.');
        }

        $this->sensitiveIdentity->execute($user, $request, null);
        $user->forceFill(['password' => $password])->save();
        $request->session()->forget('auth.identity_confirmed_at');
    }

    private function hasPassword(User $user): bool
    {
        $hash = $user->getAttribute('password');

        return is_string($hash) && $hash !== '';
    }
}
