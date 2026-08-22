<?php

namespace Tests\Support\AI;

use App\Contracts\AI\AgentModel;
use App\Enums\AI\AgentErrorCode;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelEvent;
use App\ValueObjects\AI\AgentModelRequest;
use App\ValueObjects\AI\AgentUsage;
use Generator;

final class ScriptedAgentModel implements AgentModel
{
    /** @var list<array{type: string, deltas: list<string>, usage?: AgentUsage, providerResponseId?: string, code?: AgentErrorCode, retryAfterMilliseconds?: ?int}> */
    private array $scripts = [];

    private int $invocationCount = 0;

    /**
     * @param  list<string>  $deltas
     */
    public static function completed(
        array $deltas,
        ?AgentUsage $usage = null,
        string $providerResponseId = 'resp_scripted_123',
    ): self {
        $model = new self;
        $model->scripts = [
            [
                'type' => 'completed',
                'deltas' => $deltas,
                'usage' => $usage ?? new AgentUsage(10, 0, 0, 10, 0, 20),
                'providerResponseId' => $providerResponseId,
            ],
        ];

        return $model;
    }

    /**
     * @param  list<array{code: AgentErrorCode, retryAfterMilliseconds?: ?int, deltas?: list<string>}>  $failures
     */
    public static function failures(array $failures): self
    {
        $model = new self;
        $model->scripts = array_map(
            fn (array $failure): array => [
                'type' => 'failed',
                'deltas' => $failure['deltas'] ?? [],
                'code' => $failure['code'],
                'retryAfterMilliseconds' => $failure['retryAfterMilliseconds'] ?? null,
            ],
            $failures,
        );

        return $model;
    }

    /**
     * @return Generator<int, AgentModelEvent, mixed, void>
     */
    public function stream(AgentModelRequest $request, AgentDeadline $deadline): Generator
    {
        $scriptIndex = min($this->invocationCount, max(0, count($this->scripts) - 1));
        $script = $this->scripts[$scriptIndex] ?? [
            'type' => 'completed',
            'deltas' => ['Scripted completion.'],
            'usage' => new AgentUsage(10, 0, 0, 10, 0, 20),
            'providerResponseId' => 'resp_scripted_fallback',
        ];
        $this->invocationCount++;

        foreach ($script['deltas'] as $delta) {
            $deadline->throwIfExpired();
            yield AgentModelEvent::delta($delta);
        }

        $deadline->throwIfExpired();

        if ($script['type'] === 'completed') {
            yield AgentModelEvent::completed(
                $script['usage'] ?? new AgentUsage(10, 0, 0, 10, 0, 20),
                ($script['providerResponseId'] ?? 'resp_scripted').'-'.$this->invocationCount,
            );
        } else {
            yield AgentModelEvent::failed(
                $script['code'] ?? AgentErrorCode::ProviderServerError,
                $script['retryAfterMilliseconds'] ?? null,
            );
        }
    }

    public function invocationCount(): int
    {
        return $this->invocationCount;
    }
}
