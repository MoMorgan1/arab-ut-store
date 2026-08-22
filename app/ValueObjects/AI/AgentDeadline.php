<?php

namespace App\ValueObjects\AI;

use App\Contracts\AI\MonotonicClock;
use App\Exceptions\AI\AgentDeadlineExceeded;
use InvalidArgumentException;

final readonly class AgentDeadline
{
    private function __construct(
        private MonotonicClock $clock,
        private int $expiresAtMilliseconds,
    ) {}

    public static function afterSeconds(MonotonicClock $clock, int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('An agent deadline must be at least one second.');
        }

        return new self($clock, $clock->nowMilliseconds() + ($seconds * 1000));
    }

    public function remainingMilliseconds(): int
    {
        $remainingMilliseconds = $this->expiresAtMilliseconds - $this->clock->nowMilliseconds();

        if ($remainingMilliseconds <= 0) {
            throw new AgentDeadlineExceeded;
        }

        return $remainingMilliseconds;
    }

    public function throwIfExpired(): void
    {
        $this->remainingMilliseconds();
    }
}
