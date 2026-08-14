<?php

namespace App\Actions\Checkout;

use App\Exceptions\Checkout\CheckoutPhoneUnavailable;
use App\Models\PhoneVerification;
use App\Models\User;
use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class VerifyCheckoutPhoneCode
{
    public function execute(User $user, E164Phone $phone, string $code): void
    {
        $result = DB::transaction(function () use ($code, $phone, $user): string {
            $verification = PhoneVerification::query()
                ->where('user_id', $user->id)
                ->where('phone', $phone->value())
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($verification === null || $verification->attempts >= 5) {
                return 'invalid';
            }

            $verification->increment('attempts');

            if (! Hash::check($code, $verification->code_hash)) {
                return 'invalid';
            }

            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $phoneOwner = User::query()
                ->where('phone', $phone->value())
                ->whereKeyNot($user->id)
                ->lockForUpdate()
                ->exists();

            if (! $lockedUser instanceof User || ! $lockedUser->is_active || $phoneOwner) {
                return 'unavailable';
            }

            $lockedUser->forceFill([
                'phone' => $phone->value(),
                'phone_verified_at' => now(),
            ])->save();
            $verification->forceFill(['verified_at' => now()])->save();

            return 'verified';
        }, attempts: 3);

        if ($result === 'unavailable') {
            throw new CheckoutPhoneUnavailable('The phone cannot be used for checkout.');
        }

        if ($result !== 'verified') {
            throw new DomainException('The WhatsApp verification code is invalid or expired.');
        }
    }
}
