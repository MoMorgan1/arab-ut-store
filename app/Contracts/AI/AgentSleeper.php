<?php

namespace App\Contracts\AI;

use App\ValueObjects\AI\AgentDeadline;

interface AgentSleeper
{
    public function sleepMilliseconds(int $milliseconds, AgentDeadline $deadline): void;
}
