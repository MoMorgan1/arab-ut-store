<?php

namespace App\Enums\AI;

enum AppStreamEventType: string
{
    case TurnCreated = 'turn.created';
    case Delta = 'response.delta';
    case Completed = 'response.completed';
    case Failed = 'response.failed';
}
