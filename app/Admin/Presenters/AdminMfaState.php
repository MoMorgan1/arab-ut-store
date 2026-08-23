<?php

namespace App\Admin\Presenters;

use App\Auth\TrustedDeviceRegistry;
use App\Models\User;

final class AdminMfaState
{
    public function __construct(private readonly TrustedDeviceRegistry $trustedDevices) {}

    /**
     * @return array{
     *     passwordConfigured: bool,
     *     enabled: bool,
     *     confirmed: bool,
     *     trustedDeviceCount: int,
     *     trustedDeviceDays: int,
     *     routes: array{
     *         enable: string,
     *         confirm: string,
     *         qrCode: string,
     *         recoveryCodes: string,
     *         regenerateRecoveryCodes: string,
     *         disable: string,
     *         forgetTrustedDevices: string
     *     }
     * }
     */
    public function for(User $user, string $locale): array
    {
        return [
            'passwordConfigured' => is_string($user->password),
            'enabled' => is_string($user->two_factor_secret),
            'confirmed' => $user->two_factor_confirmed_at !== null,
            'trustedDeviceCount' => $this->trustedDevices->activeCount($user),
            'trustedDeviceDays' => TrustedDeviceRegistry::LIFETIME_DAYS,
            'routes' => [
                'enable' => route('two-factor.enable', absolute: false),
                'confirm' => route('two-factor.confirm', absolute: false),
                'qrCode' => route('two-factor.qr-code', absolute: false),
                'recoveryCodes' => route('two-factor.recovery-codes', absolute: false),
                'regenerateRecoveryCodes' => route('two-factor.regenerate-recovery-codes', absolute: false),
                'disable' => route('two-factor.disable', absolute: false),
                'forgetTrustedDevices' => route('admin.security.trusted-devices.destroy', absolute: false),
            ],
        ];
    }
}
