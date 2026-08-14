<?php

namespace App\Actions\Auth;

use App\Models\PhoneVerification;
use App\Models\User;
use App\ValueObjects\E164Phone;
use App\ValueObjects\WhatsAppLoginResult;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class VerifyWhatsAppLoginCode
{
    public function execute(E164Phone $phone, string $code): WhatsAppLoginResult
    {
        $result = DB::transaction(function () use ($code, $phone): ?WhatsAppLoginResult {
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

            $user = $verification->user_id === null
                ? User::query()->where('phone', $phone->value())->lockForUpdate()->first()
                : $verification->user()->lockForUpdate()->first();

            if ($user instanceof User && (! $user->is_active || $user->phone_verified_at === null)) {
                return null;
            }

            $verification->forceFill(['verified_at' => now()])->save();

            return $user instanceof User
                ? WhatsAppLoginResult::existing($phone, $user)
                : WhatsAppLoginResult::registration($phone);
        }, attempts: 3);

        if ($result === null) {
            throw new DomainException('The WhatsApp login code is invalid or expired.');
        }

        return $result;
    }
}
