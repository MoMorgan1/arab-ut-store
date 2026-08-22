<?php

namespace App\ValueObjects\AI;

use InvalidArgumentException;

final readonly class AgentUsage
{
    public function __construct(
        public int $inputTokens,
        public int $cachedInputTokens,
        public int $cacheWriteTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $totalTokens,
    ) {
        foreach ([
            $this->inputTokens,
            $this->cachedInputTokens,
            $this->cacheWriteTokens,
            $this->outputTokens,
            $this->reasoningTokens,
            $this->totalTokens,
        ] as $tokenCount) {
            if ($tokenCount < 0) {
                throw new InvalidArgumentException('Agent usage token counts cannot be negative.');
            }
        }
    }
}
