<?php

namespace App\Actions\Auth;

use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Support\Facades\Http;

final class WhapiVerificationSender
{
    public function send(E164Phone $phone, string $code, string $locale): void
    {
        $baseUrl = rtrim((string) config('services.whapi.base_url'), '/');
        $token = trim((string) config('services.whapi.token'));
        $parts = parse_url($baseUrl);

        if ($baseUrl === ''
            || $token === ''
            || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new DomainException('Whapi is not configured.');
        }

        $body = $this->message($code, $locale);

        Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout(5)
            ->post('/messages/text', [
                'to' => ltrim($phone->value(), '+'),
                'body' => $body,
            ])
            ->throw();
    }

    private function message(string $code, string $locale): string
    {
        if ($locale === 'ar') {
            return "رمز عرب التيميت: {$code}";
        }

        return "Arab UT code: {$code}";
    }
}
