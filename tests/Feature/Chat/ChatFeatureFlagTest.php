<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Models\ChatConversation;
use App\Models\ChatMessage;

test('when chat is disabled all endpoints return 404 with chat_disabled JSON and create no state', function () {
    config()->set('chat.enabled', false);

    $storeResponse = $this->postJson(route('chat.conversations.store'));
    $storeResponse->assertNotFound()
        ->assertJsonPath('error.code', 'chat_disabled');
    expect($storeResponse->headers->get('Cache-Control'))->toContain('no-store');

    $showResponse = $this->getJson(route('chat.conversations.show', ['conversation' => '01JMAAA0000000000000000000']));
    $showResponse->assertNotFound()
        ->assertJsonPath('error.code', 'chat_disabled');
    expect($showResponse->headers->get('Cache-Control'))->toContain('no-store');

    $messageResponse = $this->postJson(route('chat.messages.store', ['conversation' => '01JMAAA0000000000000000000']), [
        'content' => 'Hello',
    ]);
    $messageResponse->assertNotFound()
        ->assertJsonPath('error.code', 'chat_disabled');
    expect($messageResponse->headers->get('Cache-Control'))->toContain('no-store');

    expect(ChatConversation::query()->count())->toBe(0)
        ->and(ChatMessage::query()->count())->toBe(0)
        ->and(session()->has(ResolveChatOwner::SESSION_KEY))->toBeFalse();
});
