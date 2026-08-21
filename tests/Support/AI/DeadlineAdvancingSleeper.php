<?php

namespace Tests\Support\AI;

use App\Contracts\AI\AgentSleeper;
use App\Contracts\AI\MonotonicClock;
use App\ValueObjects\AI\AgentDeadline;

final class DeadlineAdvancingSleeper implements AgentSleeper, MonotonicClock
{
    public int $currentTimeMilliseconds = 1_000_000;

    public int $sleepCalls = 0;

    public ?int $lastSleepMilliseconds = null;

    public function __construct(
        private readonly int $advanceOnSleepMilliseconds = 100_000,
    ) {}

    public function nowMilliseconds(): int
    {
        return $this->currentTimeMilliseconds;
    }

    public function advanceMilliseconds(int $milliseconds): void
    {
        $this->currentTimeMilliseconds += $milliseconds;
    }

    public function sleepMilliseconds(int $milliseconds, AgentDeadline $deadline): void
    {
        $this->sleepCalls++;
        $this->lastSleepMilliseconds = $milliseconds;

        $deadline->throwIfExpired();

        $this->currentTimeMilliseconds += $this->advanceOnSleepMilliseconds;

        $deadline->throwIfExpired();
    }
}
