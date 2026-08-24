<?php

use App\Enums\Support\SupportTicketStatus;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\QueryException;

it('allows only one open ticket per conversation', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create();
    SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ]);

    expect(fn () => SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ]))->toThrow(QueryException::class);
});

it('frees the slot when a ticket is resolved so the customer can reopen', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create();
    $first = SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ]);

    $first->update(['status' => SupportTicketStatus::Resolved, 'resolved_at' => now()]);

    $second = SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ]);

    expect($second->exists)->toBeTrue()
        ->and($second->ticket_number)->not->toBe($first->ticket_number);
});

it('frees the slot when a ticket is closed', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create();
    $first = SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ]);
    $first->update(['status' => SupportTicketStatus::Closed, 'closed_at' => now()]);

    expect(SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ])->exists)->toBeTrue();
});

it('cascades away with its conversation', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create();
    SupportTicket::factory()->for($conversation, 'conversation')->create();

    $conversation->delete();

    expect(SupportTicket::query()->count())->toBe(0);
});
