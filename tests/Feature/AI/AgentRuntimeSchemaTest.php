<?php

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('turn and run records cascade without message-range foreign keys', function () {
    $conversation = ChatConversation::factory()->create();
    $customer = ChatMessage::factory()->customer()->for($conversation, 'conversation')->create();

    $turn = AgentTurn::factory()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $customer->id,
        'last_customer_message_id' => $customer->id,
    ]);
    $run = AgentRun::factory()->for($turn, 'turn')->create();

    expect(Schema::hasColumns('agent_turns', [
        'public_id', 'conversation_id', 'status', 'first_customer_message_id',
        'last_customer_message_id', 'assistant_message_id', 'debounce_until',
        'prompt_version', 'attempt_count', 'started_at', 'completed_at',
        'terminal_error_code', 'active_conversation_key',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('agent_runs', [
            'public_id', 'agent_turn_id', 'attempt_number', 'provider', 'model',
            'provider_response_id', 'status', 'latency_ms', 'input_tokens',
            'cached_input_tokens', 'cache_write_tokens', 'output_tokens',
            'reasoning_tokens', 'total_tokens', 'estimated_cost_usd',
            'pricing_version', 'trace_id', 'error_code', 'started_at', 'completed_at',
        ]))->toBeTrue();

    $messageRangeForeignColumns = collect(Schema::getForeignKeys('agent_turns'))
        ->flatMap(fn (array $foreignKey): array => $foreignKey['columns'])
        ->intersect(['first_customer_message_id', 'last_customer_message_id']);

    expect($messageRangeForeignColumns)->toBeEmpty();

    $conversation->delete();

    expect($turn->fresh())->toBeNull()
        ->and($run->fresh())->toBeNull();
});

test('database rejects a second nonterminal turn for one conversation', function () {
    $conversation = ChatConversation::factory()->create();
    $first = ChatMessage::factory()->customer()->for($conversation, 'conversation')->create();
    $second = ChatMessage::factory()->customer()->for($conversation, 'conversation')->create();

    DB::table('agent_turns')->insert(validAgentTurnRow($conversation->id, $first->id));

    expect(fn () => DB::table('agent_turns')->insert(
        validAgentTurnRow($conversation->id, $second->id),
    ))->toThrow(QueryException::class);
});

test('terminal turns release the conversation for a successor', function () {
    $conversation = ChatConversation::factory()->create();
    $first = ChatMessage::factory()->customer()->for($conversation, 'conversation')->create();
    $second = ChatMessage::factory()->customer()->for($conversation, 'conversation')->create();

    $firstTurnId = DB::table('agent_turns')->insertGetId(validAgentTurnRow($conversation->id, $first->id));
    DB::table('agent_turns')->where('id', $firstTurnId)->update([
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    $secondTurnId = DB::table('agent_turns')->insertGetId(validAgentTurnRow($conversation->id, $second->id));

    expect(DB::table('agent_turns')->where('id', $firstTurnId)->value('active_conversation_key'))->toBeNull()
        ->and(DB::table('agent_turns')->where('id', $secondTurnId)->value('active_conversation_key'))
        ->toBe($conversation->id);
});

test('database rejects a duplicate turn message boundary', function () {
    $conversation = ChatConversation::factory()->create();
    $message = ChatMessage::factory()->customer()->for($conversation, 'conversation')->create();
    $firstTurn = validAgentTurnRow($conversation->id, $message->id);
    $firstTurn['status'] = 'completed';
    $firstTurn['completed_at'] = now();
    DB::table('agent_turns')->insert($firstTurn);

    $duplicateBoundary = validAgentTurnRow($conversation->id, $message->id);
    $duplicateBoundary['status'] = 'failed';
    $duplicateBoundary['completed_at'] = now();

    expect(fn () => DB::table('agent_turns')->insert($duplicateBoundary))
        ->toThrow(QueryException::class);
});

test('database rejects reusing one assistant message across turns', function () {
    $firstConversation = ChatConversation::factory()->create();
    $firstCustomer = ChatMessage::factory()->customer()->for($firstConversation, 'conversation')->create();
    $assistant = ChatMessage::factory()->assistant()->for($firstConversation, 'conversation')->create();
    $firstTurn = validAgentTurnRow($firstConversation->id, $firstCustomer->id);
    $firstTurn['status'] = 'completed';
    $firstTurn['assistant_message_id'] = $assistant->id;
    $firstTurn['completed_at'] = now();
    DB::table('agent_turns')->insert($firstTurn);

    $secondConversation = ChatConversation::factory()->create();
    $secondCustomer = ChatMessage::factory()->customer()->for($secondConversation, 'conversation')->create();
    $secondTurn = validAgentTurnRow($secondConversation->id, $secondCustomer->id);
    $secondTurn['status'] = 'completed';
    $secondTurn['assistant_message_id'] = $assistant->id;
    $secondTurn['completed_at'] = now();

    expect(fn () => DB::table('agent_turns')->insert($secondTurn))
        ->toThrow(QueryException::class);
});

test('database rejects duplicate run attempts provider response ids and trace ids', function (string $duplicateColumn) {
    $turn = AgentTurn::factory()->create();
    $firstRun = validAgentRunRow($turn->id);
    DB::table('agent_runs')->insert($firstRun);

    $duplicateRun = validAgentRunRow($turn->id);
    if ($duplicateColumn !== 'attempt_number') {
        $duplicateRun['attempt_number'] = 2;
    }
    if ($duplicateColumn !== 'provider_response_id') {
        $duplicateRun['provider_response_id'] = 'response-2';
    }
    if ($duplicateColumn === 'trace_id') {
        $duplicateRun['trace_id'] = $firstRun['trace_id'];
    }

    expect(fn () => DB::table('agent_runs')->insert($duplicateRun))
        ->toThrow(QueryException::class);
})->with([
    'attempt boundary' => 'attempt_number',
    'provider response boundary' => 'provider_response_id',
    'trace boundary' => 'trace_id',
]);

test('models expose typed statuses errors timestamps and relationships', function () {
    $turn = AgentTurn::factory()->failed(AgentErrorCode::ProviderTimeout)->create();
    $run = AgentRun::factory()->for($turn, 'turn')->failed(AgentErrorCode::ProviderTimeout)->create([
        'estimated_cost_usd' => '0.00001234',
    ]);

    expect($turn->status)->toBe(AgentTurnStatus::Failed)
        ->and($turn->terminal_error_code)->toBe(AgentErrorCode::ProviderTimeout)
        ->and($turn->completed_at)->not->toBeNull()
        ->and($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error_code)->toBe(AgentErrorCode::ProviderTimeout)
        ->and($run->estimated_cost_usd)->toBe('0.00001234')
        ->and($run->turn->is($turn))->toBeTrue()
        ->and($turn->runs()->whereKey($run->id)->exists())->toBeTrue()
        ->and($turn->conversation->agentTurns()->whereKey($turn->id)->exists())->toBeTrue();
});

test('completed turn factory links its assistant reply to the last customer message', function () {
    $turn = AgentTurn::factory()->completed()->create();

    expect($turn->assistantMessage)->not->toBeNull()
        ->and($turn->assistantMessage->conversation_id)->toBe($turn->conversation_id)
        ->and($turn->assistantMessage->reply_to_message_id)->toBe($turn->last_customer_message_id);
});

/** @return array<string, mixed> */
function validAgentTurnRow(int $conversationId, int $messageId): array
{
    return [
        'public_id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'status' => 'waiting',
        'first_customer_message_id' => $messageId,
        'last_customer_message_id' => $messageId,
        'assistant_message_id' => null,
        'debounce_until' => now(),
        'prompt_version' => 'support-v7',
        'attempt_count' => 0,
        'started_at' => null,
        'completed_at' => null,
        'terminal_error_code' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/** @return array<string, mixed> */
function validAgentRunRow(int $turnId): array
{
    return [
        'public_id' => (string) Str::ulid(),
        'agent_turn_id' => $turnId,
        'attempt_number' => 1,
        'provider' => 'fake',
        'model' => 'fake-support',
        'provider_response_id' => 'response-1',
        'status' => 'running',
        'latency_ms' => null,
        'input_tokens' => null,
        'cached_input_tokens' => null,
        'cache_write_tokens' => null,
        'output_tokens' => null,
        'reasoning_tokens' => null,
        'total_tokens' => null,
        'estimated_cost_usd' => null,
        'pricing_version' => 'fake-v1',
        'trace_id' => (string) Str::ulid(),
        'error_code' => null,
        'started_at' => now(),
        'completed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}
