<?php

use App\Enums\AI\AgentTurnStatus;
use App\Enums\Chat\ChatConversationStatus;
use App\Enums\Chat\ChatHandoffState;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;

it('deletes an open guest conversation once it passes the hour window', function (): void {
    config(['chat.guest_retention_hours' => 48]);
    $guest = ChatConversation::factory()->guest()->create([
        'status' => ChatConversationStatus::Open,
        'last_message_at' => now()->subHours(49),
    ]);

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect(ChatConversation::query()->whereKey($guest->id)->exists())->toBeFalse();
});

it('keeps a guest conversation that is still inside the window', function (): void {
    config(['chat.guest_retention_hours' => 48]);
    $guest = ChatConversation::factory()->guest()->create([
        'last_message_at' => now()->subHours(47),
    ]);

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect(ChatConversation::query()->whereKey($guest->id)->exists())->toBeTrue();
});

it('refuses to purge a guest conversation with a nonterminal agent turn', function (): void {
    config(['chat.guest_retention_hours' => 48]);
    $guest = ChatConversation::factory()->guest()->create([
        'last_message_at' => now()->subHours(72),
    ]);
    AgentTurn::factory()->for($guest, 'conversation')->create(['status' => AgentTurnStatus::Running]);

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect(ChatConversation::query()->whereKey($guest->id)->exists())->toBeTrue();
});

// Two separate exemptions keep a conversation open: a live ticket, and a live
// handoff_state. Asserting them together lets either one mask a regression in
// the other, so each gets its own case with the other deliberately absent — plus
// a control, without which all of them would pass if the sweep simply stopped
// closing anything.

it('does not auto-close a conversation that has a live ticket', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create([
        'status' => ChatConversationStatus::Open,
        'handoff_state' => ChatHandoffState::None,
        'last_message_at' => now()->subHours(48),
    ]);
    SupportTicket::factory()->for($conversation, 'conversation')->create();

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect($conversation->fresh()->status)->toBe(ChatConversationStatus::Open);
});

it('does not auto-close a conversation whose handoff is live, ticket or not', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create([
        'status' => ChatConversationStatus::Open,
        'handoff_state' => ChatHandoffState::Active,
        'last_message_at' => now()->subHours(48),
    ]);

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect($conversation->fresh()->status)->toBe(ChatConversationStatus::Open);
});

it('still auto-closes an idle conversation with neither exemption', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create([
        'status' => ChatConversationStatus::Open,
        'handoff_state' => ChatHandoffState::None,
        'last_message_at' => now()->subHours(48),
    ]);

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect($conversation->fresh()->status)->toBe(ChatConversationStatus::Closed);
});
