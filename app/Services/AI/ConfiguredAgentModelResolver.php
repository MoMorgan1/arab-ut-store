<?php

namespace App\Services\AI;

use App\Contracts\AI\AgentModel;
use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentProvider;
use Illuminate\Contracts\Container\Container;

final readonly class ConfiguredAgentModelResolver implements AgentModelResolver
{
    public function __construct(private Container $container) {}

    public function resolve(AgentProvider $provider): AgentModel
    {
        return match ($provider) {
            AgentProvider::Fake => $this->container->make(FakeAgentModel::class),
            AgentProvider::OpenAi => $this->container->make(OpenAiResponsesAgentModel::class),
        };
    }
}
