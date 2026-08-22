<?php

namespace App\Contracts\AI;

use App\Enums\AI\AgentProvider;

interface AgentModelResolver
{
    public function resolve(AgentProvider $provider): AgentModel;
}
