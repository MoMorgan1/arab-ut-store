<?php

namespace Tests\Support\AI;

use App\Contracts\AI\AgentSleeper;
use App\ValueObjects\AI\AgentDeadline;
use Illuminate\Support\Facades\DB;

final class RecordingAgentSleeper implements AgentSleeper
{
    /**
     * @var list<int>
     */
    public array $levelsAtSleep = [];

    public function sleepMilliseconds(int $milliseconds, AgentDeadline $deadline): void
    {
        $this->levelsAtSleep[] = DB::transactionLevel();
        $deadline->throwIfExpired();
    }
}
