<?php

namespace App\Enums\AI;

enum AgentTurnStatus: string
{
    case Waiting = 'waiting';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
