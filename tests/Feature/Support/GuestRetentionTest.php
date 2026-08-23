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

it('does not auto-close a conversation a human currently owns', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create([
        'status' => ChatConversationStatus::Open,
        'handoff_state' => ChatHandoffState::Active,
        'last_message_at' => now()->subHours(48),
    ]);
    SupportTicket::factory()->for($conversation, 'conversation')->create();

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect($conversation->fresh()->status)->toBe(ChatConversationStatus::Open);
});
