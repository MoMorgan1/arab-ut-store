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

    private const DIGIT_NORMALIZATION = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public function execute(string $content): void
    {
        $normalized = strtr($content, self::DIGIT_NORMALIZATION);

        if ($this->containsCredentialLabel($normalized)
            || preg_match('/\bBearer\s+\S+/iu', $normalized) === 1
            || preg_match('/\bsk-[A-Za-z0-9_-]{16,}/iu', $normalized) === 1
            || $this->containsBackupCodeSet($normalized)
            || $this->containsPaymentCard($normalized)) {
            throw new SensitiveAgentContentException;
        }
    }

    private function containsCredentialLabel(string $content): bool
    {
        $labels = array_map(fn (string $label): string => preg_quote($label, '/'), self::LABELS);

        return preg_match('/(?:'.implode('|', $labels).')/iu', $content) === 1;
    }

    private function containsBackupCodeSet(string $content): bool
    {
        preg_match_all('/(?<![0-9])[0-9]{8}(?![0-9])/', $content, $groups);

        return count(array_unique($groups[0])) >= 3;
    }

    private function containsPaymentCard(string $content): bool
    {
        preg_match_all('/(?<![0-9])(?:[0-9][ -]*){12,18}[0-9](?![0-9])/', $content, $candidates);

        foreach ($candidates[0] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate);

            if (is_string($digits) && $this->passesLuhn($digits)) {
                return true;
            }
        }

        return false;
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
