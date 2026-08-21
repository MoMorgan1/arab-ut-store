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

    public function execute(string $content): void
    {
        if ($this->containsCredentialLabel($content)
            || preg_match('/\bBearer\s+\S+/iu', $content) === 1
            || preg_match('/\bsk-[A-Za-z0-9_-]{16,}/iu', $content) === 1
            || $this->containsBackupCodeSet($content)
            || $this->containsPaymentCard($content)) {
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
