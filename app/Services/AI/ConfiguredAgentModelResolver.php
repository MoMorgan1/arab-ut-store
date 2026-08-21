<?php

namespace App\Services\AI;

use App\Contracts\AI\AgentModel;
use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentProvider;
use App\Exceptions\AI\AgentConfigurationException;
use Illuminate\Contracts\Container\Container;

final readonly class ConfiguredAgentModelResolver implements AgentModelResolver
{
    public function __construct(private Container $container) {}

    public function resolve(AgentProvider $provider): AgentModel
    {
        return match ($provider) {
            AgentProvider::Fake => $this->container->make(FakeAgentModel::class),
            default => throw new AgentConfigurationException(AgentErrorCode::ConfigurationInvalid),
        };
    }
}
