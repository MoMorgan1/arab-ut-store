<?php

namespace App\Actions\AI;

use App\Enums\AI\AgentRollout;
use App\Enums\AI\AssistantMode;
use App\Support\AI\AgentRuntimeConfig;
use App\ValueObjects\Chat\ChatOwner;

final readonly class ResolveAssistantMode
{
    public function __construct(private AgentRuntimeConfig $config) {}

    public function for(ChatOwner $owner): AssistantMode
    {
        $eligible = $this->config->enabled() && match ($this->config->rollout()) {
            AgentRollout::AuthenticatedTesters => $owner->userId() !== null
                && in_array($owner->userId(), $this->config->testUserIds(), true),
            AgentRollout::Public => true,
            default => false,
        };

        if ($eligible) {
            return AssistantMode::Agent;
        }

        return config('chat.demo_assistant', false) === true
            ? AssistantMode::Demo
            : AssistantMode::None;
    }
}
