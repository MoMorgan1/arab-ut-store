<?php

namespace App\Actions\Auth;

use App\Models\PhoneVerification;
use App\Models\User;
use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SendWhatsAppLoginCode
{
    public function execute(E164Phone $phone, string $locale): void
    {
        $user = User::query()
            ->where('phone', $phone->value())
            ->whereNotNull('phone_verified_at')
            ->where('is_active', true)
            ->first();

        if ($user === null) {
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
            'user_id' => $user->id,
            'phone' => $phone->value(),
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => null,
        ]);

        try {
            $this->send($phone, $code, $locale);
        } catch (Throwable $exception) {
            $verification->delete();

            throw new DomainException('The WhatsApp login code could not be sent.', previous: $exception);
        }
    }

    private function send(E164Phone $phone, string $code, string $locale): void
    {
        $baseUrl = rtrim((string) config('services.whapi.base_url'), '/');
        $token = trim((string) config('services.whapi.token'));

        if ($baseUrl === '' || $token === '') {
            throw new DomainException('Whapi is not configured.');
        }

        $body = $locale === 'ar'
            ? "رمز دخولك إلى عرب التيميت: {$code}\nصالح لمدة 5 دقائق. لا تشاركه مع أحد."
            : "Your Arab UT login code is: {$code}\nIt expires in 5 minutes. Do not share it.";

        Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout(5)
            ->retry(2, 250)
            ->post('/messages/text', [
                'to' => $phone->value(),
                'body' => $body,
            ])
            ->throw();
    }
}
