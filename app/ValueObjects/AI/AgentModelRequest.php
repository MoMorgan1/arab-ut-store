<?php

namespace App\ValueObjects\AI;

final readonly class AgentModelRequest
{
    /**
     * @param  list<array{role:string,content:string}>  $messages
     */
    public function __construct(
        public string $model,
        public string $instructions,
        public array $messages,
        public string $safetyIdentifier,
        public int $maxOutputTokens,
        public string $reasoningEffort,
        public string $locale,
    ) {}
}
