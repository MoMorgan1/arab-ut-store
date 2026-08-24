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

it('refuses a staff message that claims reply_to_message_id', function (): void {
    // reply_to_message_id is UNIQUE and FinalizeAgentTurn writes it when a turn
    // completes. A staff reply claiming the same customer message would kill an
    // in-flight assistant turn on that unique index at the finish line, and would
    // silently drop the customer message from future agent claims through
    // PendingAgentMessages' whereDoesntHave('reply'). Design §1.3 promised this
    // test; it did not exist until now.
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create();
    $customerMessage = ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
    ]);

    expect(fn () => ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Staff,
        'staff_user_id' => User::factory()->create()->id,
        'reply_to_message_id' => $customerMessage->id,
    ]))->toThrow(InvalidArgumentException::class);
});

it('allows an assistant message to claim reply_to_message_id', function (): void {
    // The control: the guard must reject staff authorship only, not break the
    // existing agent-reply linkage it shares the column with.
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create();
    $customerMessage = ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
    ]);

    $reply = ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Assistant,
        'reply_to_message_id' => $customerMessage->id,
    ]);

    expect($reply->reply_to_message_id)->toBe($customerMessage->id);
});
