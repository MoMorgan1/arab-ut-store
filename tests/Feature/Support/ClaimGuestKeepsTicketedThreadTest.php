<?php

use App\Actions\Chat\ClaimGuestChatConversations;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Enums\Chat\ChatHandoffState;
use App\Enums\Support\SupportTicketStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

it('keeps the user thread when it carries an active handoff and live ticket', function (): void {
    $user = User::factory()->create();
    $userConversation = ChatConversation::factory()->forUser($user)->create([
        'handoff_state' => ChatHandoffState::Active,
        'status' => ChatConversationStatus::Open,
    ]);
    $ticket = SupportTicket::factory()->for($userConversation, 'conversation')->create([
        'user_id' => $user->id,
        'status' => SupportTicketStatus::Open,
    ]);

    $rawToken = str_repeat('c', 64);
    $guestKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));
    $guestConversation = ChatConversation::factory()->forGuest($guestKey)->create([
        'status' => ChatConversationStatus::Open,
    ]);
    $guestMessage = ChatMessage::factory()->for($guestConversation, 'conversation')->create([
        'content' => 'Guest message before login',
    ]);

    app(ClaimGuestChatConversations::class)->execute(
        [ChatOwner::guest($guestKey)],
        $user,
        $guestConversation->public_id,
    );

    // User's ticketed conversation remains open, active, and retains its ticket
    $freshUserConversation = $userConversation->fresh();
    expect($freshUserConversation->status)->toBe(ChatConversationStatus::Open)
        ->and($freshUserConversation->handoff_state)->toBe(ChatHandoffState::Active)
        ->and($freshUserConversation->close_reason)->toBeNull()
        ->and($ticket->fresh()->status)->toBe(SupportTicketStatus::Open);

    // Guest conversation was closed with SupersededByLoginClaim and rekeyed to user
    $freshGuestConversation = $guestConversation->fresh();
    expect($freshGuestConversation->status)->toBe(ChatConversationStatus::Closed)
        ->and($freshGuestConversation->close_reason)->toBe(ChatConversationCloseReason::SupersededByLoginClaim)
        ->and($freshGuestConversation->user_id)->toBe($user->id)
        ->and($freshGuestConversation->guest_key)->toBeNull()
        ->and($guestMessage->fresh()->conversation_id)->toBe($freshGuestConversation->id);
});

it('keeps the user thread when it carries a requested handoff', function (): void {
    $user = User::factory()->create();
    $userConversation = ChatConversation::factory()->forUser($user)->create([
        'handoff_state' => ChatHandoffState::Requested,
        'status' => ChatConversationStatus::Open,
    ]);
    $ticket = SupportTicket::factory()->for($userConversation, 'conversation')->create([
        'user_id' => $user->id,
        'status' => SupportTicketStatus::Open,
    ]);

    $rawToken = str_repeat('d', 64);
    $guestKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));
    $guestConversation = ChatConversation::factory()->forGuest($guestKey)->create([
        'status' => ChatConversationStatus::Open,
    ]);

    app(ClaimGuestChatConversations::class)->execute(
        [ChatOwner::guest($guestKey)],
        $user,
        $guestConversation->public_id,
    );

    expect($userConversation->fresh()->status)->toBe(ChatConversationStatus::Open)
        ->and($userConversation->fresh()->handoff_state)->toBe(ChatHandoffState::Requested)
        ->and($ticket->fresh()->status)->toBe(SupportTicketStatus::Open)
        ->and($guestConversation->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($guestConversation->fresh()->close_reason)->toBe(ChatConversationCloseReason::SupersededByLoginClaim);
});

it('closes the user thread when handoff state is not live and guest thread wins', function (): void {
    $user = User::factory()->create();
    $userConversation = ChatConversation::factory()->forUser($user)->create([
        'handoff_state' => ChatHandoffState::None,
        'status' => ChatConversationStatus::Open,
    ]);

    $rawToken = str_repeat('e', 64);
    $guestKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));
    $guestConversation = ChatConversation::factory()->forGuest($guestKey)->create([
        'status' => ChatConversationStatus::Open,
    ]);

    app(ClaimGuestChatConversations::class)->execute(
        [ChatOwner::guest($guestKey)],
        $user,
        $guestConversation->public_id,
    );

    expect($guestConversation->fresh()->status)->toBe(ChatConversationStatus::Open)
        ->and($guestConversation->fresh()->user_id)->toBe($user->id)
        ->and($userConversation->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($userConversation->fresh()->close_reason)->toBe(ChatConversationCloseReason::SupersededByLoginClaim);
});
