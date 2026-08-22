<?php

namespace App\Services\AI;

use App\Support\AI\AgentRuntimeConfig;
use App\ValueObjects\AI\AgentUsage;

final readonly class EstimateAgentRunCost
{
    public function __construct(
        private AgentRuntimeConfig $config,
    ) {}

    public function for(AgentUsage $usage): string
    {
        $uncachedInput = max(
            0,
            $usage->inputTokens - $usage->cachedInputTokens - $usage->cacheWriteTokens,
        );

        $usd = (
            ($uncachedInput * (float) $this->config->inputRatePerMillion())
            + ($usage->cachedInputTokens * (float) $this->config->cachedInputRatePerMillion())
            + ($usage->cacheWriteTokens * (float) $this->config->cacheWriteRatePerMillion())
            + ($usage->outputTokens * (float) $this->config->outputRatePerMillion())
        ) / 1_000_000;

        return number_format($usd, 8, '.', '');
    }
}
