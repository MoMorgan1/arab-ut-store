<?php

use App\Enums\AI\AgentModelEventType;
use App\Services\AI\FakeAgentModel;
use App\Support\AI\AgentRuntimeConfig;
use App\Support\AI\SystemMonotonicClock;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelRequest;
use Tests\TestCase;

uses(TestCase::class);

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
