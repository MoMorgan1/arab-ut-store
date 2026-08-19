<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Models\ChatConversation;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

test('guest conversations created under previous APP_KEY are rekeyed to current APP_KEY on subsequent request', function () {
    $oldAppKey = 'base64:'.base64_encode(str_repeat('x', 32));
    $newAppKey = 'base64:'.base64_encode(str_repeat('y', 32));
    $rawToken = bin2hex(random_bytes(32));

    $oldGuestKey = hash_hmac('sha256', $rawToken, $oldAppKey);
    $newGuestKey = hash_hmac('sha256', $rawToken, $newAppKey);

    $conversation = ChatConversation::factory()->forGuest($oldGuestKey)->create();

    $session = new Store('chat-key-rotation', new ArraySessionHandler(120));
    $session->start();
    $session->put(ResolveChatOwner::SESSION_KEY, $rawToken);

    $request = Request::create('/chat/conversations', 'POST');
    $request->setLaravelSession($session);

    config()->set('app.key', $newAppKey);
    config()->set('app.previous_keys', [$oldAppKey]);

    $owner = app(ResolveChatOwner::class)->forRequest($request);

    expect($owner->guestKey())->toBe($newGuestKey)
        ->and($conversation->fresh()->guest_key)->toBe($newGuestKey);
});
