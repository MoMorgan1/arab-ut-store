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

test('usage rejects negative provider counts before they reach cost accounting', function () {
    expect(fn () => new AgentUsage(-1, 0, 0, 0, 0, 0))
        ->toThrow(InvalidArgumentException::class);
});
