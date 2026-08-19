<?php

namespace App\ValueObjects\Chat;

use InvalidArgumentException;

final readonly class ChatOwner
{
    private function __construct(
        private string $databaseKey,
        private ?int $userId,
        private ?string $guestKey,
    ) {}

    public static function user(int $userId): self
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('A chat user owner must have a positive identifier.');
        }

        return new self("user:{$userId}", $userId, null);
    }

    public static function guest(string $guestKey): self
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $guestKey) !== 1) {
            throw new InvalidArgumentException('A guest chat owner must use an opaque owner key.');
        }

        return new self("guest:{$guestKey}", null, $guestKey);
    }

    public function databaseKey(): string
    {
        return $this->databaseKey;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function guestKey(): ?string
    {
        return $this->guestKey;
    }

    public function idempotencyScope(): string
    {
        return $this->databaseKey;
    }
}
