<?php

namespace App\Account\Actions;

use App\Models\User;
use App\Models\UserIdentityChange;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class ConfirmPhoneChange
{
    public function execute(User $user, string $code): void
    {
        $confirmed = DB::transaction(function () use ($code, $user): bool {
            $change = UserIdentityChange::query()
                ->where('user_id', $user->id)
                ->where('kind', UserIdentityChange::KIND_PHONE)
                ->lockForUpdate()
                ->first();
            $expiresAt = $change?->getAttribute('expires_at');
            $candidate = $change?->getAttribute('candidate_value');

            if (! $change instanceof UserIdentityChange
                || ! $expiresAt instanceof CarbonInterface
                || ! is_string($candidate)
                || $change->consumed_at !== null
                || $expiresAt->isPast()
                || $change->attempts >= 5) {
                return false;
            }

            $change->increment('attempts');

            if (! Hash::check($code, $change->verification_hash)) {
                return false;
            }

            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $lockedUser instanceof User
                || User::query()
                    ->where('phone', $candidate)
                    ->whereKeyNot($user->id)
                    ->lockForUpdate()
                    ->exists()) {
                return false;
            }

            $lockedUser->forceFill([
                'phone' => $candidate,
                'phone_verified_at' => now(),
            ])->save();
            $change->forceFill(['consumed_at' => now()])->save();

            return true;
        }, attempts: 3);

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'code' => trans('account.profile.phone_code_invalid'),
            ]);
        }
    }
}
