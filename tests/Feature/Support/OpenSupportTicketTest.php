<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Actions\Support\OpenSupportTicket;
use App\Actions\Support\ResolveSupportTicket;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatHandoffState;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Enums\Support\SupportTicketStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SupportTicket;
use App\Models\User;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

it('returns 403 with handoff_requires_login for a guest owner', function (): void {
    $rawToken = str_repeat('b', 64);
    $guestKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));
    $conversation = ChatConversation::factory()->forGuest($guestKey)->create();

    $response = $this->withSession([ResolveChatOwner::SESSION_KEY => $rawToken])
        ->postJson(route('chat.tickets.store', ['conversation' => $conversation->public_id]));

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'handoff_requires_login');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('lets an authenticated customer open a support ticket', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => 'I need help with my FC coins order',
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('chat.tickets.store', ['conversation' => $conversation->public_id]));

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'ticket' => [
                    'publicId',
                    'ticketNumber',
                    'status',
                    'subject',
                    'createdAt',
                ],
                'handoffState',
            ],
        ])
        ->assertJsonPath('data.ticket.status', 'open')
        ->assertJsonPath('data.ticket.subject', 'I need help with my FC coins order')
        ->assertJsonPath('data.handoffState', 'requested');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($conversation->fresh()->handoff_state)->toBe(ChatHandoffState::Requested);
});

it('returns the same ticket when opened twice', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    $firstResponse = $this->actingAs($user)
        ->postJson(route('chat.tickets.store', ['conversation' => $conversation->public_id]));
    $firstResponse->assertCreated();

    $secondResponse = $this->actingAs($user)
        ->postJson(route('chat.tickets.store', ['conversation' => $conversation->public_id]));
    $secondResponse->assertCreated();

    expect($secondResponse->json('data.ticket.ticketNumber'))->toBe($firstResponse->json('data.ticket.ticketNumber'))
        ->and(SupportTicket::query()->where('conversation_id', $conversation->id)->count())->toBe(1);
});

it('derives the ticket subject from the first customer message truncated on a word boundary', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $longMessage = str_repeat('word ', 40).'end'; // > 160 chars

    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => $longMessage,
    ]);

    $ticket = app(OpenSupportTicket::class)->execute($conversation, $user);

    expect(mb_strlen($ticket->subject))->toBeLessThanOrEqual(160)
        ->and(str_ends_with($ticket->subject, ' '))->toBeFalse()
        ->and($ticket->subject)->toStartWith('word word');
});

it('uses the default localized subject when there is no customer message', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create(['locale' => 'ar']);

    $ticket = app(OpenSupportTicket::class)->execute($conversation, $user);

    expect($ticket->subject)->toBe('طلب دعم فني');
});

it('resolves a ticket, transitions handoff state to resolved, and appends a system message', function (): void {
    $user = User::factory()->create();
    $staff = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create(['locale' => 'ar']);
    $ticket = app(OpenSupportTicket::class)->execute($conversation, $user);

    expect($conversation->fresh()->handoff_state)->toBe(ChatHandoffState::Requested);

    $resolvedTicket = app(ResolveSupportTicket::class)->execute($ticket, $staff);

    expect($resolvedTicket->status)->toBe(SupportTicketStatus::Resolved)
        ->and($resolvedTicket->resolved_at)->not->toBeNull()
        ->and($resolvedTicket->assigned_admin_id)->toBe($staff->id)
        ->and($conversation->fresh()->handoff_state)->toBe(ChatHandoffState::Resolved);

    $lastMessage = $conversation->fresh()->messages()->latest('id')->first();
    expect($lastMessage->sender_type)->toBe(ChatSenderType::System)
        ->and($lastMessage->message_type)->toBe(ChatMessageType::System)
        ->and($lastMessage->content)->toContain('نواف رجع لمساعدتك');
});

it('allows opening a new ticket after the prior ticket is resolved', function (): void {
    $user = User::factory()->create();
    $staff = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $firstTicket = app(OpenSupportTicket::class)->execute($conversation, $user);

    app(ResolveSupportTicket::class)->execute($firstTicket, $staff);

    $secondTicket = app(OpenSupportTicket::class)->execute($conversation->fresh(), $user);

    expect($secondTicket->id)->not->toBe($firstTicket->id)
        ->and($secondTicket->status)->toBe(SupportTicketStatus::Open)
        ->and($firstTicket->fresh()->status)->toBe(SupportTicketStatus::Resolved)
        ->and($conversation->fresh()->handoff_state)->toBe(ChatHandoffState::Requested);
});

it('returns 409 when attempting to open a ticket on a closed conversation', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::CustomerStartedNew,
        now()->subMinute(),
    )->create();

    $this->actingAs($user)
        ->postJson(route('chat.tickets.store', ['conversation' => $conversation->public_id]))
        ->assertConflict()
        ->assertJsonPath('error.code', 'conversation_closed');
});
