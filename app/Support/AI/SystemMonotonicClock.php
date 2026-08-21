<?php

namespace App\Support\AI;

use App\Contracts\AI\MonotonicClock;

final class SystemMonotonicClock implements MonotonicClock
{
    public function nowMilliseconds(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }
}
