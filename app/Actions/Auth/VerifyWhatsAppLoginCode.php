<?php

namespace App\Actions\Auth;

use App\Models\PhoneVerification;
use App\Models\User;
use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class VerifyWhatsAppLoginCode
{
    public function execute(E164Phone $phone, string $code): User
    {
        $user = DB::transaction(function () use ($code, $phone): ?User {
            $verification = PhoneVerification::query()
                ->where('phone', $phone->value())
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($verification === null || $verification->attempts >= 5) {
                return null;
            }

            $verification->increment('attempts');

            if (! Hash::check($code, $verification->code_hash)) {
                return null;
            }

            $user = $verification->user()->lockForUpdate()->first();

            if ($user === null || ! $user->is_active || $user->phone_verified_at === null) {
                return null;
            }

            $verification->forceFill(['verified_at' => now()])->save();

            return $user;
        }, attempts: 3);

        if ($user === null) {
            throw new DomainException('The WhatsApp login code is invalid or expired.');
        }

        return $user;
    }
}
