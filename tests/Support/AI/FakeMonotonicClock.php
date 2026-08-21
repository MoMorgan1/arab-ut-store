<?php

namespace Tests\Support\AI;

use App\Contracts\AI\MonotonicClock;

final class FakeMonotonicClock implements MonotonicClock
{
    private int $initial;

    private int $lastReturned;

    public function __construct(
        private int $now = 1_000_000,
        private int $advancement = 0,
    ) {
        $this->initial = $now;
        $this->lastReturned = $now;
    }

    public static function advancingByMilliseconds(int $advancement, int $initial = 1_000_000): self
    {
        return new self($initial, $advancement);
    }

    public function nowMilliseconds(): int
    {
        $current = $this->now;
        $this->lastReturned = $current;
        $this->now += $this->advancement;

        return $current;
    }

    public function advanceByMilliseconds(int $milliseconds): void
    {
        $this->now += $milliseconds;
        $this->lastReturned = $this->now;
    }

    public function elapsedMilliseconds(): int
    {
        return $this->lastReturned - $this->initial;
    }

    public function setNowMilliseconds(int $milliseconds): void
    {
        $this->now = $milliseconds;
        $this->lastReturned = $milliseconds;
    }
}
