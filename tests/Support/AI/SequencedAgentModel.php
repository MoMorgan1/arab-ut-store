<?php

namespace Tests\Support\AI;

use App\Contracts\AI\AgentModel;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelEvent;
use App\ValueObjects\AI\AgentModelRequest;
use Generator;

/**
 * Hands each successive call to the next scripted model, and remembers the
 * requests, so a test can script the reply and the title call separately.
 */
final class SequencedAgentModel implements AgentModel
{
    /** @var list<AgentModelRequest> */
    private array $requests = [];

    /**
     * @param  list<AgentModel>  $models
     */
    public function __construct(private readonly array $models) {}

    /**
     * @return Generator<int, AgentModelEvent, mixed, void>
     */
    public function stream(AgentModelRequest $request, AgentDeadline $deadline): Generator
    {
        $index = min(count($this->requests), count($this->models) - 1);
        $this->requests[] = $request;

        yield from $this->models[$index]->stream($request, $deadline);
    }

    /** @return list<AgentModelRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function invocationCount(): int
    {
        return count($this->requests);
    }
}
