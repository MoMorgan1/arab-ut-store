<?php

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('chat.enabled', true);
    config()->set('chat.demo_assistant', true);
});

test('duplicate recovery preserves ineligibility chosen at original persistence', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $clientMessageId = (string) Str::uuid();

    config()->set('ai-assistant.enabled', false);
    $this->actingAs($user)->postJson(
        route('chat.messages.store', ['conversation' => $conversation->public_id]),
        ['content' => 'Original Phase 1 request', 'client_message_id' => $clientMessageId],
    )->assertCreated();

    $original = ChatMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('client_message_id', $clientMessageId)
        ->firstOrFail();
    $originalReplyId = $original->reply()->firstOrFail()->public_id;
    expect($original->agent_eligible_at)->toBeNull();

    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $this->actingAs($user)->postJson(
        route('chat.messages.store', ['conversation' => $conversation->public_id]),
        ['content' => 'Changed retry text', 'client_message_id' => $clientMessageId],
    )->assertCreated();

    expect($original->fresh()->agent_eligible_at)->toBeNull()
        ->and($original->fresh()->reply()->firstOrFail()->public_id)->toBe($originalReplyId)
        ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
});

test('duplicate recovery preserves eligibility chosen at original persistence', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $clientMessageId = (string) Str::uuid();

    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $this->actingAs($user)->postJson(
        route('chat.messages.store', ['conversation' => $conversation->public_id]),
        ['content' => 'Original agent request', 'client_message_id' => $clientMessageId],
    )->assertCreated()->assertJsonPath('data.demoReply', null);

    $original = ChatMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('client_message_id', $clientMessageId)
        ->firstOrFail();
    $originalEligibility = $original->agent_eligible_at;
    expect($originalEligibility)->not->toBeNull()
        ->and($original->reply()->exists())->toBeFalse();

    $this->travel(1)->minute();
    config()->set('ai-assistant.enabled', false);
    $this->actingAs($user)->postJson(
        route('chat.messages.store', ['conversation' => $conversation->public_id]),
        ['content' => 'Changed retry text', 'client_message_id' => $clientMessageId],
    )->assertCreated()->assertJsonPath('data.demoReply', null);

    expect($original->fresh()->agent_eligible_at->equalTo($originalEligibility))->toBeTrue()
        ->and($original->fresh()->reply()->exists())->toBeFalse()
        ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(1);
});

test('request cannot choose eligibility or prompt-block state', function () {
    config()->set('ai-assistant.enabled', false);
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    $this->actingAs($user)->postJson(
        route('chat.messages.store', ['conversation' => $conversation->public_id]),
        [
            'content' => 'Server-owned message state',
            'client_message_id' => (string) Str::uuid(),
            'agent_eligible_at' => now()->toISOString(),
            'agent_prompt_blocked_at' => now()->toISOString(),
        ],
    )->assertCreated();

    $customer = ChatMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('sender_type', 'customer')
        ->firstOrFail();

    expect($customer->agent_eligible_at)->toBeNull()
        ->and($customer->agent_prompt_blocked_at)->toBeNull();
});
