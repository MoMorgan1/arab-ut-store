<?php

namespace App\Security;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CoinsMaskedEmail
{
    public static function fromValidatedEmail(string $email): string
    {
        $domain = Str::afterLast($email, '@');

        return Str::substr($email, 0, 1).'***@'.$domain;
    }

    public static function isSafe(string $maskedEmail): bool
    {
        if (preg_match(
            '/\A(?<initial>[^\s@\x00-\x1F\x7F])\*{3}@(?<domain>[^\r\n]+)\z/uD',
            $maskedEmail,
            $matches,
        ) !== 1) {
            return false;
        }

        return Validator::make(
            ['email' => 'x@'.$matches['domain']],
            ['email' => ['required', 'string', 'email:rfc', 'max:254']],
        )->passes();
    }
}
