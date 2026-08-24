<?php

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

/**
 * The list is ordered by activity but was paginated by `id < cursor`. When the
 * two orders disagree — which is exactly what a staff reply on an old thread
 * causes — every page after the first silently dropped the rows whose id was
 * higher than the cursor's, and the customer could never reach them.
 */
it('reaches every conversation when activity order disagrees with id order', function (): void {
    $user = User::factory()->create();

    // Twelve threads created oldest-id first, then given activity times that
    // deliberately invert that order: the oldest row is the most recent one.
    $conversations = [];

    for ($i = 0; $i < 12; $i++) {
        $conversations[] = ChatConversation::factory()
            ->forUser($user)
            ->closed(ChatConversationCloseReason::CustomerStartedNew, now()->subDays(30))
            ->create(['last_message_at' => now()->subMinutes(12 - $i)]);
    }

    // The newest-id thread is the *least* recently active, and vice versa.
    foreach ($conversations as $index => $conversation) {
        $conversation->forceFill(['last_message_at' => now()->subMinutes($index * 5)])->save();

        ChatMessage::factory()->for($conversation, 'conversation')->create([
            'sender_type' => ChatSenderType::Customer,
            'content' => "thread {$index}",
        ]);
    }

    $seen = [];
    $cursor = null;

    for ($page = 0; $page < 5; $page++) {
        $query = ['limit' => 5];

        if ($cursor !== null) {
            $query['before_id'] = $cursor;
        }

        $response = $this->actingAs($user)->getJson(route('chat.conversations.index', $query));
        $response->assertOk();

        $items = $response->json('data.conversations');

        foreach ($items as $item) {
            $seen[] = $item['publicId'];
        }

        if ($response->json('data.hasMore') !== true) {
            break;
        }

        $cursor = $response->json('data.oldestCursor');
    }

    expect(array_unique($seen))->toHaveCount(12)
        ->and(array_values(array_unique($seen)))->toEqualCanonicalizing(
            collect($conversations)->pluck('public_id')->all()
        );
});
