<?php

use App\Actions\AI\CreateOrRecoverAgentTurn;
use App\Actions\AI\RetryAgentTurn;
use App\Enums\AI\AgentTurnStatus;
use App\Enums\Chat\ChatHandoffState;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use LogicException;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

/**
 * Explicit retry is the only claim path that bypasses CreateOrRecoverAgentTurn,
 * where the handoff guard lives. A customer whose turn failed *before* the
 * handoff keeps a live Retry control; pressing it after a human took over used
 * to re-stream the assistant into a thread a person owns.
 */
it('refuses an explicit retry while a human owns the conversation', function (ChatHandoffState $state): void {
    $customer = User::factory()->create();

    $conversation = ChatConversation::factory()->forUser($customer)->create([
        'handoff_state' => $state,
    ]);

    $turn = AgentTurn::factory()->for($conversation, 'conversation')->create([
        'status' => AgentTurnStatus::Failed,
        'terminal_error_code' => 'provider_timeout',
        'attempt_count' => 1,
    ]);

    expect(fn () => app(RetryAgentTurn::class)->execute($turn))
        ->toThrow(LogicException::class);

    // The turn must be left exactly as it was — not reset to Waiting.
    expect($turn->fresh()->status)->toBe(AgentTurnStatus::Failed);
})->with([
    'requested' => [ChatHandoffState::Requested],
    'active' => [ChatHandoffState::Active],
]);

it('allows the retry again once the ticket is resolved', function (): void {
    $customer = User::factory()->create();

    $conversation = ChatConversation::factory()->forUser($customer)->create([
        'handoff_state' => ChatHandoffState::Resolved,
    ]);

    $turn = AgentTurn::factory()->for($conversation, 'conversation')->create([
        'status' => AgentTurnStatus::Failed,
        'terminal_error_code' => 'provider_timeout',
        'attempt_count' => 1,
    ]);

    expect(app(RetryAgentTurn::class)->execute($turn)->status)
        ->toBe(AgentTurnStatus::Waiting);
});

/**
 * The claim-time guard lived inside claimPendingRange, which is only reached
 * when no Waiting/Running turn exists. A turn already Waiting at the moment of
 * takeover was handed straight back, and the pipeline drove it to a reply in a
 * thread a human owns.
 *
 * Design 3.3 permits an already-*streaming* turn to finish — killing it would
 * leave a partial bubble. A Waiting turn has produced nothing, so that
 * reasoning does not cover it.
 */
it('does not hand back a waiting turn once a human owns the conversation', function (): void {
    $customer = User::factory()->create();

    $conversation = ChatConversation::factory()->forUser($customer)->create([
        'handoff_state' => ChatHandoffState::Active,
    ]);

    AgentTurn::factory()->for($conversation, 'conversation')->create([
        'status' => AgentTurnStatus::Waiting,
    ]);

    $claim = app(CreateOrRecoverAgentTurn::class)
        ->execute($conversation->fresh(), ChatOwner::user($customer->id));

    expect($claim->isIdle())->toBeTrue()
        ->and($claim->turn)->toBeNull();
});

it('still lets a turn that is already running finish after takeover', function (): void {
    $customer = User::factory()->create();

    $conversation = ChatConversation::factory()->forUser($customer)->create([
        'handoff_state' => ChatHandoffState::Active,
    ]);

    $running = AgentTurn::factory()->for($conversation, 'conversation')->create([
        'status' => AgentTurnStatus::Running,
    ]);

    $claim = app(CreateOrRecoverAgentTurn::class)
        ->execute($conversation->fresh(), ChatOwner::user($customer->id));

    expect($claim->isIdle())->toBeFalse()
        ->and($claim->turn?->id)->toBe($running->id);
});
