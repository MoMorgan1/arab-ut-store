<?php

use App\Contracts\AI\MonotonicClock;
use App\Enums\AI\AgentModelEventType;
use App\Exceptions\AI\AgentDeadlineExceeded;
use App\Services\AI\FakeAgentModel;
use App\Support\AI\AgentRuntimeConfig;
use App\Support\AI\SystemMonotonicClock;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelRequest;
use Tests\TestCase;

uses(TestCase::class);

final class FakeAgentModelTestClock implements MonotonicClock
{
    /** @var list<int> */
    private array $milliseconds;

    /** @param list<int> $milliseconds */
    public function __construct(array $milliseconds)
    {
        $this->milliseconds = $milliseconds;
    }

    public function nowMilliseconds(): int
    {
        return array_shift($this->milliseconds) ?? 0;
    }
}

test('fake stream emits three localized deltas followed by neutral completion', function (string $locale, array $expectedDeltas) {
    config()->set('ai-assistant.fake_delta_delay_ms', 0);
    $request = new AgentModelRequest(
        model: 'gpt-5.6-luna',
        instructions: 'Support instructions.',
        messages: [['role' => 'user', 'content' => $locale === 'en' ? 'Help' : 'ساعدني']],
        safetyIdentifier: str_repeat('a', 64),
        maxOutputTokens: 500,
        reasoningEffort: 'low',
        locale: $locale,
    );
    $deadline = AgentDeadline::afterSeconds(
        app(SystemMonotonicClock::class),
        app(AgentRuntimeConfig::class)->requestTimeoutSeconds(),
    );

    $events = iterator_to_array(app(FakeAgentModel::class)->stream($request, $deadline));

    expect($events)->toHaveCount(4)
        ->and(array_column($events, 'type'))->toBe([
            AgentModelEventType::Delta,
            AgentModelEventType::Delta,
            AgentModelEventType::Delta,
            AgentModelEventType::Completed,
        ])
        ->and(array_column(array_slice($events, 0, 3), 'delta'))->toBe($expectedDeltas)
        ->and($events[3]->usage->totalTokens)->toBe(0)
        ->and($events[3]->providerResponseId)->toBeNull();
})->with([
    'Arabic' => [
        'ar',
        [
            'استلمت رسائلك. ',
            'هذا رد اختبار متدفق وثابت. ',
            'بيانات الطلبات المباشرة غير متاحة في هذه المرحلة.',
        ],
    ],
    'English' => [
        'en',
        [
            'I received your messages. ',
            'This is a deterministic streamed test. ',
            'Live order data is unavailable in this phase.',
        ],
    ],
]);

test('fake stream stops without completion when its deadline expires', function (array $clockMilliseconds, array $expectedEventTypes) {
    config()->set('ai-assistant.fake_delta_delay_ms', 0);
    $request = new AgentModelRequest(
        model: 'gpt-5.6-luna',
        instructions: 'Support instructions.',
        messages: [['role' => 'user', 'content' => 'Help']],
        safetyIdentifier: str_repeat('a', 64),
        maxOutputTokens: 500,
        reasoningEffort: 'low',
        locale: 'en',
    );
    $deadline = AgentDeadline::afterSeconds(new FakeAgentModelTestClock($clockMilliseconds), 1);
    $events = [];
    $deadlineException = null;

    try {
        foreach ((new FakeAgentModel(app(AgentRuntimeConfig::class)))->stream($request, $deadline) as $event) {
            $events[] = $event;
        }
    } catch (AgentDeadlineExceeded $exception) {
        $deadlineException = $exception;
    }

    expect($deadlineException)->toBeInstanceOf(AgentDeadlineExceeded::class)
        ->and(array_column($events, 'type'))->toBe($expectedEventTypes);
})->with([
    'before the first delta' => [[0, 1_000], []],
    'after an inter-delta delay' => [[0, 0, 0, 1_000], [AgentModelEventType::Delta]],
    'after all deltas before completion' => [[0, 0, 0, 0, 0, 0, 1_000], [
        AgentModelEventType::Delta,
        AgentModelEventType::Delta,
        AgentModelEventType::Delta,
    ]],
]);
