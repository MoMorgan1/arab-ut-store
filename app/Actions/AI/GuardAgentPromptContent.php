<?php

namespace App\Actions\AI;

use App\Exceptions\AI\SensitiveAgentContentException;

final class GuardAgentPromptContent
{
    private const LABELS = [
        'password', 'passcode', 'backup code', 'recovery code', 'API key',
        'secret', 'token', 'CVV', 'CVC', 'كلمة المرور', 'كلمه المرور',
        'رمز احتياطي', 'رموز احتياطية', 'مفتاح API', 'رمز التحقق',
    ];

    private const CARD_TERMS = [
        'card', 'debit', 'credit', 'PAN',
        'بطاقة', 'بطاقتي', 'بطاقة ائتمان', 'بطاقة خصم', 'رقم البطاقة',
    ];

    private const DIGIT_NORMALIZATION = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public function execute(string $content): void
    {
        $normalized = strtr($content, self::DIGIT_NORMALIZATION);

        if ($this->containsCredentialWithSecret($normalized)
            || preg_match('/\bBearer\s+\S+/iu', $normalized) === 1
            || preg_match('/\bsk-[A-Za-z0-9_-]{16,}/iu', $normalized) === 1
            || $this->containsPaymentCard($normalized)) {
            throw new SensitiveAgentContentException;
        }
    }

    private function containsCredentialWithSecret(string $content): bool
    {
        if (! $this->containsCredentialLabel($content)) {
            return false;
        }

        return $this->containsSecretValue($content);
    }

    private function containsCredentialLabel(string $content): bool
    {
        $labels = array_map(fn (string $label): string => preg_quote($label, '/'), self::LABELS);

        return preg_match('/(?:'.implode('|', $labels).')/iu', $content) === 1;
    }

    private function containsSecretValue(string $content): bool
    {
        if (preg_match('/(?:"[^"\r\n]{6,}"|\'[^\'\r\n]{6,}\'|[“«][^”»\r\n]{6,}[”»]|[‘][^’\r\n]{6,}[’])/u', $content) === 1) {
            return true;
        }

        if (preg_match('/:\s*\S{6,}/u', $content) === 1) {
            return true;
        }

        if (preg_match('/[A-Za-z0-9_-]{12,}/', $content) === 1) {
            return true;
        }

        $hasCvvCvc = preg_match('/\b(?:CVV|CVC)\b/iu', $content) === 1;
        $minDigits = $hasCvvCvc ? 3 : 8;

        return preg_match('/[0-9]{'.$minDigits.',}/', $content) === 1;
    }

    private function containsPaymentCard(string $content): bool
    {
        if (! $this->containsCardTerminology($content)) {
            return false;
        }

        preg_match_all('/(?<![0-9])(?:[0-9][ -]*){12,18}[0-9](?![0-9])/', $content, $candidates);

        foreach ($candidates[0] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate);

            if (is_string($digits) && $this->passesLuhn($digits)) {
                return true;
            }
        }

        return false;
    }

    private function containsCardTerminology(string $content): bool
    {
        $terms = array_map(function (string $term): string {
            if (preg_match('/^[A-Za-z]+$/', $term) === 1) {
                return '\b'.preg_quote($term, '/').'\b';
            }

            return preg_quote($term, '/');
        }, self::CARD_TERMS);

        return preg_match('/(?:'.implode('|', $terms).')/iu', $content) === 1;
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $parity = strlen($digits) % 2;

        foreach (str_split($digits) as $index => $character) {
            $digit = (int) $character;
            if ($index % 2 === $parity) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
