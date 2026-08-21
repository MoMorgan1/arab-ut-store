<?php

use App\Actions\AI\PrepareAutomaticAgentRetry;
use App\Actions\AI\StreamAgentTurn;
use App\Contracts\AI\AgentModel;
use App\Contracts\AI\AgentModelResolver;
use App\Contracts\AI\AgentSleeper;
use App\Contracts\AI\MonotonicClock;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Enums\AI\AppStreamEventType;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Services\AI\AgentTurnRetryPolicy;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelEvent;
use App\ValueObjects\AI\AgentModelRequest;
use App\ValueObjects\AI\AgentUsage;
use App\ValueObjects\AI\AppStreamEvent;
use App\ValueObjects\Chat\ChatOwner;
use Tests\Support\AI\DeadlineAdvancingSleeper;
use Tests\Support\AI\ScriptedAgentModel;
use Tests\Support\AI\ScriptedAgentModelResolver;

beforeEach(function (): void {
    config()->set('ai-assistant.provider', 'fake');
});

test('prepare automatic agent retry marks run failed rate limited and returns turn to waiting', function () {
    $turn = AgentTurn::factory()->running()->create(['attempt_count' => 1]);
    $run = AgentRun::factory()->running()->for($turn, 'turn')->create(['attempt_number' => 1]);

    $freshTurn = app(PrepareAutomaticAgentRetry::class)->execute($turn, $run);

    $freshRun = $run->fresh();

    expect($freshRun->status)->toBe(AgentRunStatus::Failed)
        ->and($freshRun->error_code)->toBe(AgentErrorCode::RateLimited)
        ->and($freshRun->completed_at)->not->toBeNull()
        ->and($freshTurn->status)->toBe(AgentTurnStatus::Waiting)
        ->and($freshTurn->terminal_error_code)->toBeNull()
        ->and($freshTurn->completed_at)->toBeNull()
        ->and($freshTurn->attempt_count)->toBe(1)
        ->and($freshTurn->assistant_message_id)->toBeNull()
        ->and(app(AgentTurnRetryPolicy::class)->canRetry($freshTurn))->toBeFalse();
});

test('automatic 429 retry sleeps outside lock and succeeds on attempt two', function () {
    config()->set('ai-assistant.retry_after_cap_ms', 500);

    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);

    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver(
        ScriptedAgentModel::failures([
            ['code' => AgentErrorCode::RateLimited, 'retryAfterMilliseconds' => 50],
            ['code' => AgentErrorCode::RateLimited, 'deltas' => ['Recovered response.']],
        ]),
    ));

    // For second attempt, use completed response
    $scriptedModel = new class implements AgentModel
    {
        private int $calls = 0;

        public function stream(AgentModelRequest $request, AgentDeadline $deadline): Generator
        {
            $this->calls++;
            if ($this->calls === 1) {
                yield AgentModelEvent::failed(AgentErrorCode::RateLimited, 50);
            } else {
                yield AgentModelEvent::delta('Recovered successfully.');
                yield AgentModelEvent::completed(
                    new AgentUsage(10, 0, 0, 10, 0, 20),
                    'resp_recovered_123',
                );
            }
        }
    };
    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver($scriptedModel));

    $events = iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner));

    $freshTurn = $turn->fresh();
    expect($freshTurn->status)->toBe(AgentTurnStatus::Completed)
        ->and($freshTurn->attempt_count)->toBe(2)
        ->and($freshTurn->assistant_message_id)->not->toBeNull();

    $runs = AgentRun::query()->where('agent_turn_id', $turn->id)->orderBy('attempt_number')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs[0]->status)->toBe(AgentRunStatus::Failed)
        ->and($runs[0]->error_code)->toBe(AgentErrorCode::RateLimited)
        ->and($runs[1]->status)->toBe(AgentRunStatus::Completed)
        ->and($runs[1]->provider_response_id)->toBe('resp_recovered_123');

    $completedEvent = collect($events)->first(fn (AppStreamEvent $e): bool => $e->type === AppStreamEventType::Completed);
    expect($completedEvent)->not->toBeNull();
});

test('deadline expiry during automatic retry wait yields one run failed rate limited and terminal provider timeout', function () {
    config()->set('ai-assistant.retry_after_cap_ms', 2000);

    $sleeper = new DeadlineAdvancingSleeper(advanceOnSleepMilliseconds: 100_000);
    app()->instance(MonotonicClock::class, $sleeper);
    app()->instance(AgentSleeper::class, $sleeper);

    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);

    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver(
        ScriptedAgentModel::failures([
            ['code' => AgentErrorCode::RateLimited, 'retryAfterMilliseconds' => 500],
        ]),
    ));

    $events = iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner));

    $freshTurn = $turn->fresh();
    $runs = AgentRun::query()->where('agent_turn_id', $turn->id)->get();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe(AgentRunStatus::Failed)
        ->and($runs[0]->error_code)->toBe(AgentErrorCode::RateLimited)
        ->and($freshTurn->status)->toBe(AgentTurnStatus::Failed)
        ->and($freshTurn->terminal_error_code)->toBe(AgentErrorCode::ProviderTimeout)
        ->and($sleeper->sleepCalls)->toBe(1);

    $failedEvent = collect($events)->first(fn (AppStreamEvent $e): bool => $e->type === AppStreamEventType::Failed);
    expect($failedEvent)->not->toBeNull()
        ->and($failedEvent->errorCode)->toBe(AgentErrorCode::ProviderTimeout);
});

test('automatic retry delay is capped by retry after cap ms setting', function () {
    config()->set('ai-assistant.retry_after_cap_ms', 300);

    $mockSleeper = new class implements AgentSleeper
    {
        public int $sleptMilliseconds = 0;

        public function sleepMilliseconds(int $milliseconds, AgentDeadline $deadline): void
        {
            $this->sleptMilliseconds = $milliseconds;
        }
    };
    app()->instance(AgentSleeper::class, $mockSleeper);

    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);

    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver(
        ScriptedAgentModel::failures([
            ['code' => AgentErrorCode::RateLimited, 'retryAfterMilliseconds' => 5000],
            ['code' => AgentErrorCode::RateLimited, 'retryAfterMilliseconds' => 5000],
        ]),
    ));

    iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner));

    expect($mockSleeper->sleptMilliseconds)->toBe(300);
});
