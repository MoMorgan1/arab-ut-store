<?php

namespace App\Admin\Presenters;

use App\Models\User;

final class AdminMfaState
{
    /**
     * @return array{
     *     passwordConfigured: bool,
     *     enabled: bool,
     *     confirmed: bool,
     *     routes: array{
     *         enable: string,
     *         confirm: string,
     *         qrCode: string,
     *         recoveryCodes: string,
     *         regenerateRecoveryCodes: string,
     *         disable: string
     *     }
     * }
     */
    public function for(User $user, string $locale): array
    {
        return [
            'passwordConfigured' => is_string($user->password),
            'enabled' => is_string($user->two_factor_secret),
            'confirmed' => $user->two_factor_confirmed_at !== null,
            'routes' => [
                'enable' => route('two-factor.enable', absolute: false),
                'confirm' => route('two-factor.confirm', absolute: false),
                'qrCode' => route('two-factor.qr-code', absolute: false),
                'recoveryCodes' => route('two-factor.recovery-codes', absolute: false),
                'regenerateRecoveryCodes' => route('two-factor.regenerate-recovery-codes', absolute: false),
                'disable' => route('two-factor.disable', absolute: false),
            ],
        ];
    }
}
