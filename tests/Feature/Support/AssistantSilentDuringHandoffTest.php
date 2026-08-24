<?php

use App\Actions\AI\CreateOrRecoverAgentTurn;
use App\Actions\Chat\CreateChatMessage;
use App\Enums\Chat\ChatHandoffState;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'public');
    config()->set('ai-assistant.provider', 'fake');
});

it('does not claim a message that was already eligible when the human took over', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    // Eligible message written BEFORE takeover — agent_eligible_at is immutable.
    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'agent_eligible_at' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(5),
    ]);

    $conversation->update(['handoff_state' => ChatHandoffState::Active]);

    $claim = app(CreateOrRecoverAgentTurn::class)
        ->execute($conversation->fresh(), ChatOwner::user($user->id));

    expect($claim->isIdle())->toBeTrue()
        ->and($claim->turn)->toBeNull();
});

it('does not claim a message when handoff state is requested', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'agent_eligible_at' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(5),
    ]);

    $conversation->update(['handoff_state' => ChatHandoffState::Requested]);

    $claim = app(CreateOrRecoverAgentTurn::class)
        ->execute($conversation->fresh(), ChatOwner::user($user->id));

    expect($claim->isIdle())->toBeTrue()
        ->and($claim->turn)->toBeNull();
});

it('claims again once the ticket is resolved', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create([
        'handoff_state' => ChatHandoffState::Resolved,
    ]);
    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'agent_eligible_at' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(5),
    ]);

    $claim = app(CreateOrRecoverAgentTurn::class)
        ->execute($conversation->fresh(), ChatOwner::user($user->id));

    expect($claim->isIdle())->toBeFalse()
        ->and($claim->turn)->not->toBeNull();
});

it('does not mark a message eligible while a human owns the thread', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create([
        'handoff_state' => ChatHandoffState::Active,
    ]);

    $result = app(CreateChatMessage::class)->execute(
        $conversation,
        'Customer message during active human handoff',
        (string) Str::uuid(),
        ChatOwner::user($user->id),
    );

    expect($result['message']->agent_eligible_at)->toBeNull();
});

it('does not send a demo reply when handoff is live', function (): void {
    config()->set('ai-assistant.enabled', false);
    config()->set('chat.demo_assistant', true);

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create([
        'handoff_state' => ChatHandoffState::Active,
    ]);

    $result = app(CreateChatMessage::class)->execute(
        $conversation,
        'Message during active handoff with demo assistant config',
        (string) Str::uuid(),
        ChatOwner::user($user->id),
    );

    expect($result['demoReply'])->toBeNull()
        ->and($conversation->messages()->where('sender_type', ChatSenderType::Assistant)->exists())->toBeFalse();
});
