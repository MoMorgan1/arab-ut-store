<?php

namespace App\Contracts\AI;

use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelEvent;
use App\ValueObjects\AI\AgentModelRequest;
use Generator;

interface AgentModel
{
    /** @return Generator<int, AgentModelEvent, mixed, void> */
    public function stream(AgentModelRequest $request, AgentDeadline $deadline): Generator;
}
