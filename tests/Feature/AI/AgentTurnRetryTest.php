<?php

use App\Actions\AI\RetryAgentTurn;
use App\Actions\AI\StreamAgentTurn;
use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentTurn;
use App\Models\ChatMessage;
use App\Services\AI\AgentTurnRetryPolicy;
use App\ValueObjects\Chat\ChatOwner;
use Tests\Support\AI\ScriptedAgentModel;
use Tests\Support\AI\ScriptedAgentModelResolver;

beforeEach(function (): void {
    config()->set('ai-assistant.provider', 'fake');
});

test('one bounded automatic 429 retry leaves attempt three for an explicit retry', function () {
    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);
    config()->set('ai-assistant.retry_after_cap_ms', 0);
    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver(
        ScriptedAgentModel::failures([
            ['code' => AgentErrorCode::RateLimited, 'retryAfterMilliseconds' => 5000],
            ['code' => AgentErrorCode::RateLimited, 'retryAfterMilliseconds' => 5000],
        ]),
    ));

    iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner));

    expect($turn->fresh()->status)->toBe(AgentTurnStatus::Failed)
        ->and($turn->fresh()->attempt_count)->toBe(2)
        ->and(app(AgentTurnRetryPolicy::class)->canRetry($turn->fresh()))->toBeTrue();

    $retriedTurn = app(RetryAgentTurn::class)->execute($turn->fresh());

    expect($retriedTurn->status)->toBe(AgentTurnStatus::Waiting)
        ->and($retriedTurn->attempt_count)->toBe(2)
        ->and($retriedTurn->terminal_error_code)->toBeNull();
});

test('transient error codes permit explicit retry when attempt budget remains', function (AgentErrorCode $transientCode) {
    $turn = AgentTurn::factory()->failed(AgentErrorCode::ProviderServerError)->create([
        'attempt_count' => 1,
        'terminal_error_code' => $transientCode,
    ]);

    expect(app(AgentTurnRetryPolicy::class)->canRetry($turn))->toBeTrue();

    $retried = app(RetryAgentTurn::class)->execute($turn);
    expect($retried->status)->toBe(AgentTurnStatus::Waiting)
        ->and($retried->terminal_error_code)->toBeNull()
        ->and($retried->attempt_count)->toBe(1);
})->with([
    [AgentErrorCode::RateLimited],
    [AgentErrorCode::ProviderConnectionFailed],
    [AgentErrorCode::ProviderTimeout],
    [AgentErrorCode::ProviderServerError],
    [AgentErrorCode::ProviderIncomplete],
    [AgentErrorCode::StreamTerminated],
    [AgentErrorCode::StaleTurnRecovered],
]);

test('non transient error codes refuse explicit retry even when attempt budget remains', function (AgentErrorCode $nonTransientCode) {
    $turn = AgentTurn::factory()->failed(AgentErrorCode::ProviderServerError)->create([
        'attempt_count' => 1,
        'terminal_error_code' => $nonTransientCode,
    ]);

    expect(app(AgentTurnRetryPolicy::class)->canRetry($turn))->toBeFalse();

    expect(fn () => app(RetryAgentTurn::class)->execute($turn))
        ->toThrow(LogicException::class);
})->with([
    [AgentErrorCode::SensitiveContentBlocked],
    [AgentErrorCode::ConfigurationInvalid],
    [AgentErrorCode::InvalidAgentRequest],
    [AgentErrorCode::ProviderAuthenticationFailed],
    [AgentErrorCode::ProviderPermissionDenied],
    [AgentErrorCode::ProviderRequestRejected],
    [AgentErrorCode::ProviderMalformed],
    [AgentErrorCode::ProviderTerminalFailure],
    [AgentErrorCode::Cancelled],
]);

test('explicit retry is refused when attempt budget of 3 is exhausted', function () {
    $turn = AgentTurn::factory()->failed(AgentErrorCode::ProviderServerError)->create([
        'attempt_count' => 3,
        'terminal_error_code' => AgentErrorCode::ProviderServerError,
    ]);

    expect(app(AgentTurnRetryPolicy::class)->canRetry($turn))->toBeFalse();
    expect(fn () => app(RetryAgentTurn::class)->execute($turn))
        ->toThrow(LogicException::class);
});

test('explicit retry is refused when turn already has an assistant message', function () {
    $message = ChatMessage::factory()->create();
    $turn = AgentTurn::factory()->failed(AgentErrorCode::ProviderServerError)->create([
        'attempt_count' => 1,
        'terminal_error_code' => AgentErrorCode::ProviderServerError,
        'assistant_message_id' => $message->id,
    ]);

    expect(app(AgentTurnRetryPolicy::class)->canRetry($turn))->toBeFalse();
    expect(fn () => app(RetryAgentTurn::class)->execute($turn))
        ->toThrow(LogicException::class);
});
