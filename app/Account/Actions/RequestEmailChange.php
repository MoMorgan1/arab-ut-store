<?php

namespace App\Account\Actions;

use App\Models\User;
use App\Models\UserIdentityChange;
use App\Notifications\PendingEmailChangeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class RequestEmailChange
{
    public function __construct(private VerifySensitiveIdentityAction $sensitiveIdentity) {}

    public function execute(User $user, Request $request, string $candidate, ?string $currentPassword): void
    {
        $email = Str::lower(trim($candidate));
        $this->sensitiveIdentity->execute($user, $request, $currentPassword);

        if ($email === Str::lower($user->email) || User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereKeyNot($user->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'email' => trans('validation.unique', ['attribute' => 'email']),
            ]);
        }

        $token = Str::random(64);
        $change = UserIdentityChange::query()->updateOrCreate(
            ['user_id' => $user->id, 'kind' => UserIdentityChange::KIND_EMAIL],
            [
                'candidate_value' => $email,
                'candidate_hash' => UserIdentityChange::candidateHash($email),
                'verification_hash' => hash('sha256', $token),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(30),
                'last_sent_at' => now(),
                'consumed_at' => null,
            ],
        );
        $locale = app()->getLocale();
        $url = URL::temporarySignedRoute(
            $locale === 'en'
                ? 'localized.account.profile.email.confirm'
                : 'account.profile.email.confirm',
            now()->addMinutes(30),
            ['change' => $change->public_id, 'token' => $token],
        );

        Notification::route('mail', $email)
            ->notify(new PendingEmailChangeNotification($url, $locale));
    }
}
