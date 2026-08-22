<?php

use App\Contracts\AI\MonotonicClock;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentModelEventType;
use App\Exceptions\AI\AgentDeadlineExceeded;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelEvent;
use App\ValueObjects\AI\AgentUsage;

test('deadline expires at its monotonic boundary', function () {
    $clock = new class implements MonotonicClock
    {
        public int $milliseconds = 10_000;

        public function nowMilliseconds(): int
        {
            return $this->milliseconds;
        }
    };
    $deadline = AgentDeadline::afterSeconds($clock, 2);

    expect($deadline->remainingMilliseconds())->toBe(2_000);

    $clock->milliseconds = 12_000;

    expect(fn () => $deadline->throwIfExpired())
        ->toThrow(AgentDeadlineExceeded::class);
});

test('model events expose only the fields valid for their neutral type', function () {
    $usage = new AgentUsage(10, 2, 0, 4, 1, 15);
    $delta = AgentModelEvent::delta('مرحبا');
    $completed = AgentModelEvent::completed($usage, 'response_123');
    $failed = AgentModelEvent::failed(AgentErrorCode::RateLimited, 250);

    expect($delta->type)->toBe(AgentModelEventType::Delta)
        ->and($delta->delta)->toBe('مرحبا')
        ->and($delta->usage)->toBeNull()
        ->and($completed->type)->toBe(AgentModelEventType::Completed)
        ->and($completed->usage)->toBe($usage)
        ->and($completed->providerResponseId)->toBe('response_123')
        ->and($failed->type)->toBe(AgentModelEventType::Failed)
        ->and($failed->errorCode)->toBe(AgentErrorCode::RateLimited)
        ->and($failed->retryAfterMilliseconds)->toBe(250);
});

test('usage rejects every negative provider count before it reaches cost accounting', function (array $tokenCounts) {
    expect(fn () => new AgentUsage(...$tokenCounts))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'input tokens' => [[-1, 0, 0, 0, 0, 0]],
    'cached input tokens' => [[0, -1, 0, 0, 0, 0]],
    'cache write tokens' => [[0, 0, -1, 0, 0, 0]],
    'output tokens' => [[0, 0, 0, -1, 0, 0]],
    'reasoning tokens' => [[0, 0, 0, 0, -1, 0]],
    'total tokens' => [[0, 0, 0, 0, 0, -1]],
]);

test('model events reject invalid legal states', function (string $eventType) {
    $createEvent = match ($eventType) {
        'delta' => fn () => AgentModelEvent::delta(''),
        'completed' => fn () => AgentModelEvent::completed(new AgentUsage(0, 0, 0, 0, 0, 0), ''),
        'failed' => fn () => AgentModelEvent::failed(AgentErrorCode::RateLimited, -1),
    };

    expect($createEvent)->toThrow(InvalidArgumentException::class);
})->with([
    'empty delta' => ['delta'],
    'empty provider response identifier' => ['completed'],
    'negative retry delay' => ['failed'],
]);

test('deadline rejects a zero-second duration', function () {
    $clock = new class implements MonotonicClock
    {
        public function nowMilliseconds(): int
        {
            return 0;
        }
    };

    expect(fn () => AgentDeadline::afterSeconds($clock, 0))
        ->toThrow(InvalidArgumentException::class);
});
