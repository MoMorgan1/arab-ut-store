<?php

use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Http\Presenters\ChatPresenter;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

it('never returns an internal note in the customer payload', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create();
    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => 'visible to the customer',
    ]);
    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Staff,
        'message_type' => ChatMessageType::InternalNote,
        'staff_user_id' => User::factory()->create()->id,
        'content' => 'SECRET-OPERATOR-NOTE',
    ]);

    $loaded = app(ChatPresenter::class)->loadBoundedMessages($conversation);
    $payload = json_encode($loaded['messages']->all(), JSON_THROW_ON_ERROR);

    expect($payload)->not->toContain('SECRET-OPERATOR-NOTE')
        ->and($payload)->toContain('visible to the customer');
});

it('rejects a staff message without a staff author', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create();

    expect(fn () => ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Staff,
        'staff_user_id' => null,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects a staff author on a non-staff message', function (): void {
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create();

    expect(fn () => ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'staff_user_id' => User::factory()->create()->id,
    ]))->toThrow(InvalidArgumentException::class);
});
