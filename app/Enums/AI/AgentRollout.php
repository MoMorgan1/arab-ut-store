<?php

namespace App\Enums\AI;

enum AgentRollout: string
{
    case Disabled = 'disabled';
    case AuthenticatedTesters = 'authenticated_testers';
    case Public = 'public';
}
