<?php

use App\Actions\AI\CreateOrRecoverAgentTurn;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('claim waits for quiet then takes the approved default 24 customers', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    config()->set('ai-assistant.turn_debounce_ms', 1500);
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);

    $messages = ChatMessage::factory()->count(25)->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()]);

    $waiting = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);

    expect($waiting->turn)->toBeNull()
        ->and($waiting->retryAfterMilliseconds)->toBe(1500)
        ->and($waiting->hasPendingMessages)->toBeTrue()
        ->and($waiting->shouldStart)->toBeFalse();

    $this->travel(1500)->milliseconds();
    $claimed = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);

    expect($claimed->turn)->toBeInstanceOf(AgentTurn::class)
        ->and($claimed->turn?->first_customer_message_id)->toBe($messages->first()->id)
        ->and($claimed->turn?->last_customer_message_id)->toBe($messages->get(23)->id)
        ->and($claimed->hasPendingMessages)->toBeTrue()
        ->and($claimed->shouldStart)->toBeTrue();
});

test('configured claim limit leaves only eligible rows after the authoritative boundary pending', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    config()->set('ai-assistant.max_context_messages', 10);
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $messages = ChatMessage::factory()->count(11)->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()->subSeconds(2)]);

    $claim = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);

    expect($claim->turn?->first_customer_message_id)->toBe($messages->first()->id)
        ->and($claim->turn?->last_customer_message_id)->toBe($messages->get(9)->id)
        ->and($claim->hasPendingMessages)->toBeTrue();
});

test('active turn recovery never grants a second start and ignores ineligible rows after its range', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $claimed = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()->subSeconds(2)]);
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $claimed->id,
        'last_customer_message_id' => $claimed->id,
    ]);
    ChatMessage::factory()->customer()->for($conversation, 'conversation')->create();

    $recovered = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);

    expect($recovered->turn?->is($turn))->toBeTrue()
        ->and($recovered->shouldStart)->toBeFalse()
        ->and($recovered->hasPendingMessages)->toBeFalse();
});

test('claim excludes replied blocked system and other-conversation rows from its FIFO range', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $conversation = ChatConversation::factory()->create();
    $otherConversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $createdAt = now()->subSeconds(2);

    $replied = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => $createdAt]);
    ChatMessage::factory()->assistant()->for($conversation, 'conversation')->create([
        'reply_to_message_id' => $replied->id,
    ]);
    ChatMessage::factory()->customer()->agentEligible()->for($conversation, 'conversation')->create([
        'agent_prompt_blocked_at' => now(),
        'created_at' => $createdAt,
    ]);
    ChatMessage::factory()->system()->for($conversation, 'conversation')->create([
        'agent_eligible_at' => now(),
        'created_at' => $createdAt,
    ]);
    ChatMessage::factory()->customer()->agentEligible()->for($otherConversation, 'conversation')->create([
        'created_at' => $createdAt,
    ]);
    $eligible = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => $createdAt]);

    $claim = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);

    expect($claim->turn?->first_customer_message_id)->toBe($eligible->id)
        ->and($claim->turn?->last_customer_message_id)->toBe($eligible->id)
        ->and($claim->hasPendingMessages)->toBeFalse();
});

test('completed ranges advance the cursor even when no eligible message remains', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $claimed = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()->subSeconds(2)]);
    AgentTurn::factory()->completed()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $claimed->id,
        'last_customer_message_id' => $claimed->id,
    ]);

    $idle = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);

    expect($idle->turn)->toBeNull()
        ->and($idle->retryAfterMilliseconds)->toBe(0)
        ->and($idle->hasPendingMessages)->toBeFalse()
        ->and($idle->shouldStart)->toBeFalse()
        ->and(AgentTurn::query()->where('status', AgentTurnStatus::Waiting)->count())->toBe(0);
});

test('claim rethrows database failures outside the named turn uniqueness races', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()->subSeconds(2)]);

    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The synthetic unrelated-failure trigger relies on SQLite semantics; named-race recovery on MariaDB is covered by AgentTurnConcurrencyTest.');
    }

    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_unrelated_agent_turn_insert
        BEFORE INSERT ON agent_turns
        BEGIN
            SELECT RAISE(ABORT, 'synthetic unrelated database failure');
        END
        SQL);

    try {
        expect(fn () => app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner))
            ->toThrow(QueryException::class, 'synthetic unrelated database failure');
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS fail_unrelated_agent_turn_insert');
    }
});

test('twenty five eligible rows drain as twenty four plus one across two starts', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $messages = ChatMessage::factory()->count(25)->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()->subSeconds(2)]);
    $action = app(CreateOrRecoverAgentTurn::class);

    $first = $action->execute($conversation, $owner);

    expect($first->turn)->toBeInstanceOf(AgentTurn::class)
        ->and($first->turn?->first_customer_message_id)->toBe($messages->first()->id)
        ->and($first->turn?->last_customer_message_id)->toBe($messages->get(23)->id)
        ->and($first->hasPendingMessages)->toBeTrue();

    $first->turn?->forceFill([
        'status' => AgentTurnStatus::Completed,
        'completed_at' => now(),
    ])->save();

    $second = $action->execute($conversation, $owner);

    expect($second->turn)->toBeInstanceOf(AgentTurn::class)
        ->and($second->turn?->first_customer_message_id)->toBe($messages->get(24)->id)
        ->and($second->turn?->last_customer_message_id)->toBe($messages->get(24)->id)
        ->and($second->hasPendingMessages)->toBeFalse()
        ->and($second->shouldStart)->toBeTrue();
});

test('a nondefault limit drains the same fifo in configured chunks', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    config()->set('ai-assistant.max_context_messages', 10);
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    ChatMessage::factory()->count(25)->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()->subSeconds(2)]);
    $action = app(CreateOrRecoverAgentTurn::class);
    $bounds = [];

    foreach (range(1, 3) as $start) {
        $claim = $action->execute($conversation, $owner);

        expect($claim->turn)->toBeInstanceOf(AgentTurn::class);
        $bounds[] = [(int) $claim->turn->first_customer_message_id, (int) $claim->turn->last_customer_message_id];
        $claim->turn->forceFill([
            'status' => AgentTurnStatus::Completed,
            'completed_at' => now(),
        ])->save();
    }

    expect($bounds[0][1] - $bounds[0][0])->toBe(9)
        ->and($bounds[1][0])->toBe($bounds[0][1] + 1)
        ->and($bounds[1][1] - $bounds[1][0])->toBe(9)
        ->and($bounds[2][0])->toBe($bounds[1][1] + 1)
        ->and($bounds[2][1] - $bounds[2][0])->toBe(4);

    $drained = $action->execute($conversation, $owner);

    expect($drained->turn)->toBeNull()
        ->and($drained->hasPendingMessages)->toBeFalse();
});

test('a failed turn range is never reclaimed by a new claim', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    ChatMessage::factory()->count(3)->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()->subSeconds(2)]);
    $action = app(CreateOrRecoverAgentTurn::class);

    $failed = $action->execute($conversation, $owner);
    expect($failed->turn)->toBeInstanceOf(AgentTurn::class);

    $failed->turn?->forceFill([
        'status' => AgentTurnStatus::Failed,
        'terminal_error_code' => AgentErrorCode::StreamTerminated,
        'completed_at' => now(),
    ])->save();

    $this->travel(1500)->milliseconds();
    $later = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()->subSeconds(2)]);

    $next = $action->execute($conversation, $owner);

    expect($next->turn)->toBeInstanceOf(AgentTurn::class)
        ->and($next->turn?->first_customer_message_id)->toBe($later->id)
        ->and($next->turn?->last_customer_message_id)->toBe($later->id)
        ->and(AgentTurn::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
});

test('the quiet window keeps millisecond fidelity while waiting and at creation', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    config()->set('ai-assistant.turn_debounce_ms', 1500);
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()]);
    $action = app(CreateOrRecoverAgentTurn::class);

    $waiting = $action->execute($conversation, $owner);

    expect($waiting->turn)->toBeNull()
        ->and($waiting->retryAfterMilliseconds)->toBe(1500);

    $this->travel(1499)->milliseconds();
    $almost = $action->execute($conversation, $owner);

    expect($almost->turn)->toBeNull()
        ->and($almost->retryAfterMilliseconds)->toBe(1);

    $this->travel(1)->milliseconds();
    $claimed = $action->execute($conversation, $owner);

    expect($claimed->turn)->toBeInstanceOf(AgentTurn::class)
        ->and(DB::table('agent_turns')->where('public_id', $claimed->turn?->public_id)->value('debounce_until'))
        ->toContain('.500');
});
