<?php

namespace App\ValueObjects;

use App\Models\User;

final readonly class WhatsAppLoginResult
{
    private function __construct(
        public E164Phone $phone,
        public ?User $user,
    ) {}

    public static function existing(E164Phone $phone, User $user): self
    {
        return new self($phone, $user);
    }

    public static function registration(E164Phone $phone): self
    {
        return new self($phone, null);
    }

    public function needsRegistration(): bool
    {
        return $this->user === null;
    }
}
