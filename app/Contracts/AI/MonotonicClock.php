<?php

namespace App\Contracts\AI;

interface MonotonicClock
{
    public function nowMilliseconds(): int;
}
