<?php

namespace App\Services\AI;

use App\Contracts\AI\AgentModel;
use App\Support\AI\AgentRuntimeConfig;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelEvent;
use App\ValueObjects\AI\AgentModelRequest;
use App\ValueObjects\AI\AgentUsage;
use Generator;

final readonly class FakeAgentModel implements AgentModel
{
    public function __construct(private AgentRuntimeConfig $config) {}

    /** @return Generator<int, AgentModelEvent, mixed, void> */
    public function stream(AgentModelRequest $request, AgentDeadline $deadline): Generator
    {
        $deltas = $request->locale === 'en'
            ? [
                'I received your messages. ',
                'This is a deterministic streamed test. ',
                'Live order data is unavailable in this phase.',
            ]
            : [
                'استلمت رسائلك. ',
                'هذا رد اختبار متدفق وثابت. ',
                'بيانات الطلبات المباشرة غير متاحة في هذه المرحلة.',
            ];

        foreach ($deltas as $index => $delta) {
            if ($index > 0) {
                $deadline->throwIfExpired();
                usleep($this->config->fakeDeltaDelayMilliseconds() * 1000);
            }

            $deadline->throwIfExpired();
            yield AgentModelEvent::delta($delta);
        }

        yield AgentModelEvent::completed(new AgentUsage(0, 0, 0, 0, 0, 0), null);
    }
}
