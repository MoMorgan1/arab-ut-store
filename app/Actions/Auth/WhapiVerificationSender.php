<?php

namespace App\Actions\Auth;

use App\ValueObjects\E164Phone;
use DomainException;
use Illuminate\Support\Facades\Http;

final class WhapiVerificationSender
{
    public function send(E164Phone $phone, string $code, string $locale, string $purpose): void
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

        $body = $this->message($code, $locale, $purpose);

        Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout(5)
            ->post('/messages/text', [
                'to' => $phone->value(),
                'body' => $body,
            ])
            ->throw();
    }

    private function message(string $code, string $locale, string $purpose): string
    {
        if ($locale === 'ar') {
            $label = $purpose === 'checkout' ? 'توثيق رقمك لإتمام الدفع' : 'تسجيل الدخول';

            return "رمز {$label} في عرب التيميت: {$code}\nصالح لمدة 5 دقائق. لا تشاركه مع أحد.";
        }

        $label = $purpose === 'checkout' ? 'phone verification' : 'login';

        return "Your Arab UT {$label} code is: {$code}\nIt expires in 5 minutes. Do not share it.";
    }
}
