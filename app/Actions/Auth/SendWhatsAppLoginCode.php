<?php

namespace App\Actions\Auth;

use App\Models\PhoneVerification;
use App\Models\User;
use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Throwable;

final readonly class SendWhatsAppLoginCode
{
    public function __construct(private WhapiVerificationSender $sender) {}

    public function execute(E164Phone $phone, string $locale): void
    {
        $user = User::query()->where('phone', $phone->value())->first();

        if ($user instanceof User && ($user->phone_verified_at === null || ! $user->is_active)) {
            Hash::make((string) random_int(100000, 999999));

            return;
        }

        $recent = PhoneVerification::query()
            ->where('phone', $phone->value())
            ->whereNull('verified_at')
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($recent) {
            return;
        }

        $code = (string) random_int(100000, 999999);
        $verification = PhoneVerification::create([
            'user_id' => $user?->id,
            'phone' => $phone->value(),
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => null,
        ]);

        try {
            $this->sender->send($phone, $code, $locale);
        } catch (Throwable $exception) {
            $verification->delete();

            throw new DomainException('The WhatsApp login code could not be sent.', previous: $exception);
        }
    }
}
