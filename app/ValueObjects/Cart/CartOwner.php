<?php

namespace App\ValueObjects\Cart;

use InvalidArgumentException;

final readonly class CartOwner
{
    private function __construct(
        private string $databaseKey,
        private ?int $userId,
        private ?string $sessionKey,
    ) {}

    public static function user(int $userId): self
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('A cart user owner must have a positive identifier.');
        }

        return new self("user:{$userId}", $userId, null);
    }

    public static function guest(string $sessionHmac): self
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $sessionHmac) !== 1) {
            throw new InvalidArgumentException('A guest cart owner must use an opaque owner key.');
        }

        return new self("guest:{$sessionHmac}", null, $sessionHmac);
    }

    public function databaseKey(): string
    {
        return $this->databaseKey;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function sessionKey(): ?string
    {
        return $this->sessionKey;
    }

    public function idempotencyScope(): string
    {
        return $this->databaseKey;
    }
}
