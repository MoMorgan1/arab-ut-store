<?php

use App\Enums\Chat\ChatHandoffState;
use App\Models\ChatConversation;
use App\Support\ChatNumber;

it('gives every conversation a unique short id', function (): void {
    $first = ChatConversation::factory()->create();
    $second = ChatConversation::factory()->create();

    expect($first->short_id)->toMatch(ChatNumber::PATTERN)
        ->and($second->short_id)->toMatch(ChatNumber::PATTERN)
        ->and($first->short_id)->not->toBe($second->short_id);
});

it('defaults handoff state to none', function (): void {
    expect(ChatConversation::factory()->create()->handoff_state)
        ->toBe(ChatHandoffState::None);
});

it('scopes to conversations a human currently owns', function (): void {
    ChatConversation::factory()->create(['handoff_state' => ChatHandoffState::None]);
    ChatConversation::factory()->create(['handoff_state' => ChatHandoffState::Offered]);
    $requested = ChatConversation::factory()->create(['handoff_state' => ChatHandoffState::Requested]);
    $active = ChatConversation::factory()->create(['handoff_state' => ChatHandoffState::Active]);
    ChatConversation::factory()->create(['handoff_state' => ChatHandoffState::Resolved]);

    $ids = ChatConversation::query()->withLiveHandoff()->pluck('id')->all();

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($requested->id)
        ->and($ids)->toContain($active->id);
});
