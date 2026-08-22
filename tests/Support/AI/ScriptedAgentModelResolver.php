<?php

namespace Tests\Support\AI;

use App\Contracts\AI\AgentModel;
use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentProvider;

final class ScriptedAgentModelResolver implements AgentModelResolver
{
    public int $resolutionCalls = 0;

    public function __construct(
        private readonly AgentModel $model,
    ) {}

    public function resolve(AgentProvider $provider): AgentModel
    {
        $this->resolutionCalls++;

        return $this->model;
    }
}
