<?php

use App\Actions\AI\BuildAgentModelRequest;
use App\Actions\AI\CreateOrRecoverAgentTurn;
use App\Enums\AI\AgentErrorCode;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('first AI claim excludes Phase 1 demo and old unreplied ineligible history', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);
    $demoCustomer = ChatMessage::factory()->customer()->for($conversation, 'conversation')->create();
    ChatMessage::factory()->assistant()->for($conversation, 'conversation')->create([
        'reply_to_message_id' => $demoCustomer->id,
        'content' => 'Phase 1 demo must never enter agent context.',
    ]);
    ChatMessage::factory()->customer()->for($conversation, 'conversation')->create([
        'content' => 'Old unreplied history must remain ineligible.',
    ]);
    $eligible = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create([
            'content' => 'First eligible Phase 2 message.',
            'created_at' => now()->subSeconds(2),
        ]);

    $claim = app(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);

    expect($claim->turn?->first_customer_message_id)->toBe($eligible->id)
        ->and($claim->turn?->last_customer_message_id)->toBe($eligible->id);
});

test('next prompt uses only completed agent context and the current claimed customers', function () {
    config()->set('ai-assistant.max_context_messages', 5);
    $conversation = ChatConversation::factory()->create();
    $owner = ChatOwner::guest((string) $conversation->guest_key);

    $demoCustomer = ChatMessage::factory()->customer()->for($conversation, 'conversation')->create([
        'content' => 'phase-1-customer',
    ]);
    ChatMessage::factory()->assistant()->for($conversation, 'conversation')->create([
        'reply_to_message_id' => $demoCustomer->id,
        'content' => 'phase-1-assistant',
    ]);
    $completedCustomer = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => 'completed-customer']);
    $completedTurn = AgentTurn::factory()->completed()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $completedCustomer->id,
        'last_customer_message_id' => $completedCustomer->id,
    ]);
    $completedTurn->assistantMessage()->update(['content' => 'completed-assistant']);

    $failedCustomer = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['content' => 'failed-customer']);
    AgentTurn::factory()->failed(AgentErrorCode::ProviderTimeout)->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $failedCustomer->id,
        'last_customer_message_id' => $failedCustomer->id,
    ]);
    ChatMessage::factory()->assistant()->for($conversation, 'conversation')->create([
        'reply_to_message_id' => $failedCustomer->id,
        'content' => 'arbitrary-assistant',
    ]);
    ChatMessage::factory()->system()->for($conversation, 'conversation')->create([
        'content' => 'system-onboarding',
    ]);
    ChatMessage::factory()->customer()->agentEligible()->for($conversation, 'conversation')->create([
        'content' => 'blocked-customer',
        'agent_prompt_blocked_at' => now(),
    ]);
    $current = ChatMessage::factory()->count(2)->customer()->agentEligible()
        ->for($conversation, 'conversation')->sequence(
            ['content' => 'current-one'],
            ['content' => 'current-two'],
        )->create();
    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $current->first()->id,
        'last_customer_message_id' => $current->last()->id,
    ]);
    ChatMessage::factory()->customer()->agentEligible()->for($conversation, 'conversation')->create([
        'content' => 'later-customer',
    ]);
    ChatMessage::factory()->customer()->agentEligible()->create(['content' => 'other-conversation']);

    $request = app(BuildAgentModelRequest::class)->execute($turn, $owner, 'SAR');

    expect(array_column($request->messages, 'content'))->toBe([
        'completed-customer',
        'completed-assistant',
        'current-one',
        'current-two',
    ]);
});
