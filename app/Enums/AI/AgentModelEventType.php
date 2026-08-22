<?php

namespace App\Enums\AI;

enum AgentModelEventType: string
{
    case Delta = 'delta';
    case Completed = 'completed';
    case Failed = 'failed';
}
