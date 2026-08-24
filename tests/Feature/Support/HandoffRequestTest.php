<?php

use App\Actions\Chat\CreateChatMessage;
use App\Enums\Chat\ChatHandoffState;
use App\Enums\Support\SupportTicketStatus;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'public');
    config()->set('ai-assistant.provider', 'fake');
});

it('opens a support ticket and marks handoff requested when an authenticated customer asks for a person', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create([
        'handoff_state' => ChatHandoffState::None,
    ]);

    $result = app(CreateChatMessage::class)->execute(
        $conversation,
        'أبي أكلم موظف لو سمحت',
        (string) Str::uuid(),
        ChatOwner::user($user->id),
    );

    $fresh = $conversation->fresh();
    expect($fresh->handoff_state)->toBe(ChatHandoffState::Requested)
        ->and($result['message']->agent_eligible_at)->toBeNull();

    $ticket = SupportTicket::query()->where('conversation_id', $conversation->id)->first();
    expect($ticket)->not->toBeNull()
        ->and($ticket->status)->toBe(SupportTicketStatus::Open)
        ->and($ticket->user_id)->toBe($user->id)
        ->and($ticket->subject)->toBe('أبي أكلم موظف لو سمحت');
});

it('does not open a ticket when a guest sends a handoff phrase', function (): void {
    $rawToken = str_repeat('a', 64);
    $guestKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));
    $conversation = ChatConversation::factory()->forGuest($guestKey)->create([
        'handoff_state' => ChatHandoffState::None,
    ]);

    $result = app(CreateChatMessage::class)->execute(
        $conversation,
        'connect me to an agent',
        (string) Str::uuid(),
        ChatOwner::guest($guestKey),
    );

    $fresh = $conversation->fresh();
    expect($fresh->handoff_state)->toBe(ChatHandoffState::None)
        ->and(SupportTicket::query()->where('conversation_id', $conversation->id)->exists())->toBeFalse();
});

it('does not open a duplicate ticket if conversation already has live handoff', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create([
        'handoff_state' => ChatHandoffState::Requested,
    ]);
    $existingTicket = SupportTicket::factory()->for($conversation, 'conversation')->create([
        'user_id' => $user->id,
        'status' => SupportTicketStatus::Open,
    ]);

    app(CreateChatMessage::class)->execute(
        $conversation,
        'can I talk to a human please',
        (string) Str::uuid(),
        ChatOwner::user($user->id),
    );

    expect(SupportTicket::query()->where('conversation_id', $conversation->id)->count())->toBe(1)
        ->and($conversation->fresh()->handoff_state)->toBe(ChatHandoffState::Requested);
});

it('does not open a ticket on an ordinary message', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create([
        'handoff_state' => ChatHandoffState::None,
    ]);

    $result = app(CreateChatMessage::class)->execute(
        $conversation,
        'كم سعر ٥٠٠ ألف كوينز؟',
        (string) Str::uuid(),
        ChatOwner::user($user->id),
    );

    expect($conversation->fresh()->handoff_state)->toBe(ChatHandoffState::None)
        ->and($result['message']->agent_eligible_at)->not->toBeNull()
        ->and(SupportTicket::query()->where('conversation_id', $conversation->id)->exists())->toBeFalse();
});
