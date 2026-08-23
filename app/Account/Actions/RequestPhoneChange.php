<?php

namespace App\Account\Actions;

use App\Actions\Auth\WhapiVerificationSender;
use App\Models\User;
use App\Models\UserIdentityChange;
use App\ValueObjects\E164Phone;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class RequestPhoneChange
{
    public function __construct(
        private WhapiVerificationSender $sender,
    ) {}

    public function execute(User $user, E164Phone $candidate, string $locale): void
    {
        $phone = $candidate->value();

        if ($phone === $user->phone || User::query()
            ->where('phone', $phone)
            ->whereKeyNot($user->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'phone' => trans('account.profile.phone_taken'),
            ]);
        }

        $existing = UserIdentityChange::query()
            ->where('user_id', $user->id)
            ->where('kind', UserIdentityChange::KIND_PHONE)
            ->first();
        $lastSentAt = $existing?->getAttribute('last_sent_at');

        if ($existing instanceof UserIdentityChange
            && $lastSentAt instanceof CarbonInterface
            && $existing->candidate_hash === UserIdentityChange::candidateHash($phone)
            && $existing->consumed_at === null
            && $lastSentAt->isAfter(now()->subMinute())) {
            return;
        }

        $code = (string) random_int(100000, 999999);
        $change = UserIdentityChange::query()->updateOrCreate(
            ['user_id' => $user->id, 'kind' => UserIdentityChange::KIND_PHONE],
            [
                'candidate_value' => $phone,
                'candidate_hash' => UserIdentityChange::candidateHash($phone),
                'verification_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5),
                'last_sent_at' => now(),
                'consumed_at' => null,
            ],
        );

        try {
            $this->sender->send($candidate, $code, $locale);
        } catch (Throwable $exception) {
            $change->delete();

            throw new DomainException('The WhatsApp verification code could not be sent.', previous: $exception);
        }
    }
}
