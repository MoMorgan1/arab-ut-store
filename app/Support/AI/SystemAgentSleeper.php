<?php

namespace App\Support\AI;

use App\Contracts\AI\AgentSleeper;
use App\ValueObjects\AI\AgentDeadline;

final class SystemAgentSleeper implements AgentSleeper
{
    public function sleepMilliseconds(int $milliseconds, AgentDeadline $deadline): void
    {
        $deadline->throwIfExpired();

        if ($milliseconds <= 0) {
            return;
        }

        $cappedSleepMs = min($milliseconds, $deadline->remainingMilliseconds());

        usleep($cappedSleepMs * 1000);

        $deadline->throwIfExpired();
    }
}
