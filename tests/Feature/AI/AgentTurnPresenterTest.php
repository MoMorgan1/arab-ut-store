<?php

use App\Enums\AI\AgentErrorCode;
use App\Http\Presenters\AgentTurnPresenter;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatMessage;
use Illuminate\Support\Str;

test('turn presentation exposes bounded state and no run internals', function () {
    $turn = AgentTurn::factory()->failed(AgentErrorCode::ProviderTimeout)->create([
        'attempt_count' => 2,
    ]);
    AgentRun::factory()->for($turn, 'turn')->create([
        'provider' => 'openai',
        'model' => 'gpt-5.6-luna',
        'trace_id' => (string) Str::ulid(),
        'estimated_cost_usd' => '0.00100000',
    ]);

    $payload = app(AgentTurnPresenter::class)->turn($turn);

    expect($payload)->toHaveKeys([
        'publicId', 'status', 'attemptCount', 'retryable',
        'hasPendingMessages', 'errorCode', 'message',
    ])->not->toHaveKeys([
        'provider', 'model', 'traceId', 'tokens', 'latencyMs', 'estimatedCostUsd',
    ]);
});

test('sensitive and configuration failures are never presented as retryable', function (AgentErrorCode $code) {
    $turn = AgentTurn::factory()->failed($code)->create([
        'attempt_count' => 1,
    ]);

    expect(app(AgentTurnPresenter::class)->turn($turn)['retryable'])->toBeFalse();
})->with([
    AgentErrorCode::SensitiveContentBlocked,
    AgentErrorCode::ConfigurationInvalid,
    AgentErrorCode::InvalidAgentRequest,
    AgentErrorCode::ProviderMalformed,
    AgentErrorCode::ProviderTerminalFailure,
]);

test('terminal pending signal is derived from eligible rows after the turn', function () {
    $turn = AgentTurn::factory()->completed()->create();
    ChatMessage::factory()->customer()->agentEligible()
        ->for($turn->conversation, 'conversation')->create();

    expect(app(AgentTurnPresenter::class)->turn($turn)['hasPendingMessages'])
        ->toBeTrue();
});

test('non-terminal turn never signals pending messages even if eligible rows exist', function () {
    $turn = AgentTurn::factory()->running()->create();
    ChatMessage::factory()->customer()->agentEligible()
        ->for($turn->conversation, 'conversation')->create();

    expect(app(AgentTurnPresenter::class)->turn($turn)['hasPendingMessages'])
        ->toBeFalse();
});

test('completed turn presents assistant message formatted by chat presenter', function () {
    $turn = AgentTurn::factory()->completed()->create();
    $turn->load(['assistantMessage', 'conversation']);

    $payload = app(AgentTurnPresenter::class)->turn($turn);

    expect($payload['message'])->toBeArray()
        ->and($payload['message']['publicId'])->toBe($turn->assistantMessage->public_id)
        ->and($payload['message']['conversationPublicId'])->toBe($turn->conversation->public_id)
        ->and($payload['errorCode'])->toBeNull();
});

test('failed turn without assistant message presents message as null', function () {
    $turn = AgentTurn::factory()->failed(AgentErrorCode::RateLimited)->create([
        'attempt_count' => 1,
    ]);

    $payload = app(AgentTurnPresenter::class)->turn($turn);

    expect($payload['message'])->toBeNull()
        ->and($payload['errorCode'])->toBe(AgentErrorCode::RateLimited->value)
        ->and($payload['retryable'])->toBeTrue();
});
