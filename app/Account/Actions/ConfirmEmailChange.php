<?php

namespace App\Account\Actions;

use App\Models\User;
use App\Models\UserIdentityChange;
use App\Notifications\EmailChangedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

final class ConfirmEmailChange
{
    public function execute(User $user, UserIdentityChange $change, string $token, string $locale): void
    {
        $oldEmail = DB::transaction(function () use ($change, $token, $user): string {
            $lockedChange = UserIdentityChange::query()->whereKey($change->id)->lockForUpdate()->first();
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $expiresAt = $lockedChange?->getAttribute('expires_at');
            $candidate = $lockedChange?->getAttribute('candidate_value');

            if (! $lockedChange instanceof UserIdentityChange
                || ! $lockedUser instanceof User
                || ! $expiresAt instanceof CarbonInterface
                || ! is_string($candidate)
                || $lockedChange->user_id !== $lockedUser->id
                || $lockedChange->kind !== UserIdentityChange::KIND_EMAIL
                || $lockedChange->consumed_at !== null
                || $expiresAt->isPast()
                || ! hash_equals($lockedChange->verification_hash, hash('sha256', $token))) {
                throw ValidationException::withMessages(['email' => trans('account.profile.email_link_invalid')]);
            }

            if (User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($candidate)])
                ->whereKeyNot($lockedUser->id)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages(['email' => trans('validation.unique', ['attribute' => 'email'])]);
            }

            $oldEmail = $lockedUser->email;
            $lockedUser->forceFill([
                'email' => $candidate,
                'email_verified_at' => now(),
            ])->save();
            $lockedChange->forceFill(['consumed_at' => now()])->save();

            return $oldEmail;
        }, attempts: 3);

        Notification::route('mail', $oldEmail)
            ->notify(new EmailChangedNotification($locale));
    }
}
