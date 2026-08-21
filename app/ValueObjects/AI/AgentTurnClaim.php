<?php

namespace App\ValueObjects\AI;

use App\Models\AgentTurn;
use InvalidArgumentException;

final readonly class AgentTurnClaim
{
    private function __construct(
        public ?AgentTurn $turn,
        public int $retryAfterMilliseconds,
        public bool $hasPendingMessages,
        public bool $shouldStart,
    ) {
        if ($retryAfterMilliseconds < 0
            || ($turn !== null && $retryAfterMilliseconds !== 0)
            || ($shouldStart && $turn === null)
            || ($retryAfterMilliseconds > 0 && ! $hasPendingMessages)) {
            throw new InvalidArgumentException('Invalid agent turn claim state.');
        }
    }

    public static function waiting(int $retryAfterMilliseconds): self
    {
        if ($retryAfterMilliseconds < 1) {
            throw new InvalidArgumentException('An agent turn wait must be positive.');
        }

        return new self(null, $retryAfterMilliseconds, true, false);
    }

    public static function created(AgentTurn $turn, bool $hasPendingMessages): self
    {
        return new self($turn, 0, $hasPendingMessages, true);
    }

    public static function existing(AgentTurn $turn, bool $hasPendingMessages): self
    {
        return new self($turn, 0, $hasPendingMessages, false);
    }

    public static function idle(): self
    {
        return new self(null, 0, false, false);
    }
}
