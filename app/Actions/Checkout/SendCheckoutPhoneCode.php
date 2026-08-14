<?php

namespace App\Actions\Checkout;

use App\Actions\Auth\WhapiVerificationSender;
use App\Exceptions\Checkout\CheckoutPhoneUnavailable;
use App\Models\PhoneVerification;
use App\Models\User;
use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Throwable;

final readonly class SendCheckoutPhoneCode
{
    public function __construct(private WhapiVerificationSender $sender) {}

    public function execute(User $user, E164Phone $phone, string $locale): void
    {
        if ($user->phone_verified_at !== null || User::query()
            ->where('phone', $phone->value())
            ->whereKeyNot($user->id)
            ->exists()) {
            throw new CheckoutPhoneUnavailable('The phone cannot be used for checkout.');
        }

        $recent = PhoneVerification::query()
            ->where('user_id', $user->id)
            ->where('phone', $phone->value())
            ->whereNull('verified_at')
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($recent) {
            return;
        }

        $code = (string) random_int(100000, 999999);
        $verification = PhoneVerification::create([
            'user_id' => $user->id,
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

            throw new DomainException('The WhatsApp verification code could not be sent.', previous: $exception);
        }
    }
}
