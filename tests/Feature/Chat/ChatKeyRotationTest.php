<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

afterEach(function (): void {
    Carbon::setTestNow();
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

test('rotated guest consolidation preserves the pointed open conversation before rekeying all history', function () {
    $oldAppKey = 'base64:'.base64_encode(str_repeat('o', 32));
    $newAppKey = 'base64:'.base64_encode(str_repeat('n', 32));
    $rawToken = str_repeat('a', 64);
    $oldGuestKey = hash_hmac('sha256', $rawToken, $oldAppKey);
    $newGuestKey = hash_hmac('sha256', $rawToken, $newAppKey);
    $currentOpen = ChatConversation::factory()->forGuest($newGuestKey)->create([
        'last_message_at' => now(),
    ]);
    $pointedOpen = ChatConversation::factory()->forGuest($oldGuestKey)->create([
        'last_message_at' => now()->subHour(),
    ]);
    $history = ChatConversation::factory()->forGuest($oldGuestKey)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDay(),
    )->create();
    ChatMessage::factory()->create(['conversation_id' => $currentOpen->id]);
    ChatMessage::factory()->create(['conversation_id' => $pointedOpen->id]);
    ChatMessage::factory()->create(['conversation_id' => $history->id]);

    config()->set('app.key', $newAppKey);
    config()->set('app.previous_keys', [$oldAppKey]);

    $response = $this->withSession([
        ResolveChatOwner::SESSION_KEY => $rawToken,
        ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY => $pointedOpen->public_id,
    ])->postJson(route('chat.conversations.store'));

    $response->assertOk()->assertJsonPath('data.publicId', $pointedOpen->public_id);
    expect($pointedOpen->fresh()->status)->toBe(ChatConversationStatus::Open)
        ->and($pointedOpen->fresh()->guest_key)->toBe($newGuestKey)
        ->and($currentOpen->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($currentOpen->fresh()->close_reason)->toBe(ChatConversationCloseReason::InvariantUpgradeDuplicate)
        ->and($currentOpen->fresh()->guest_key)->toBe($newGuestKey)
        ->and($history->fresh()->guest_key)->toBe($newGuestKey)
        ->and(ChatConversation::query()->where('active_owner_key', "guest:{$newGuestKey}")->count())->toBe(1)
        ->and(ChatMessage::query()->whereIn('conversation_id', [$currentOpen->id, $pointedOpen->id, $history->id])->count())->toBe(3);
});

test('rotated guest consolidation keeps the newest deterministic open candidate without a session pointer', function () {
    $oldAppKey = 'base64:'.base64_encode(str_repeat('p', 32));
    $newAppKey = 'base64:'.base64_encode(str_repeat('q', 32));
    $rawToken = str_repeat('b', 64);
    $oldGuestKey = hash_hmac('sha256', $rawToken, $oldAppKey);
    $newGuestKey = hash_hmac('sha256', $rawToken, $newAppKey);
    $sameActivity = now()->subMinute();
    $lowerId = ChatConversation::factory()->forGuest($newGuestKey)->create([
        'last_message_at' => $sameActivity,
    ]);
    $higherId = ChatConversation::factory()->forGuest($oldGuestKey)->create([
        'last_message_at' => $sameActivity,
    ]);

    config()->set('app.key', $newAppKey);
    config()->set('app.previous_keys', [$oldAppKey]);

    $response = $this->withSession([
        ResolveChatOwner::SESSION_KEY => $rawToken,
    ])->postJson(route('chat.conversations.store'));

    $response->assertOk()->assertJsonPath('data.publicId', $higherId->public_id);
    expect($higherId->fresh()->status)->toBe(ChatConversationStatus::Open)
        ->and($higherId->fresh()->guest_key)->toBe($newGuestKey)
        ->and($lowerId->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($lowerId->fresh()->close_reason)->toBe(ChatConversationCloseReason::InvariantUpgradeDuplicate);
});

test('rotated owner resolution does not rewrite history already using the current HMAC', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    $oldAppKey = 'base64:'.base64_encode(str_repeat('u', 32));
    $newAppKey = 'base64:'.base64_encode(str_repeat('v', 32));
    $rawToken = str_repeat('d', 64);
    $newGuestKey = hash_hmac('sha256', $rawToken, $newAppKey);
    $originalUpdatedAt = now()->subDays(90);
    $history = ChatConversation::factory()->forGuest($newGuestKey)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(60),
    )->create(['last_message_at' => null]);
    DB::table('chat_conversations')->where('id', $history->id)->update([
        'last_message_at' => null,
        'closed_at' => null,
        'updated_at' => $originalUpdatedAt,
    ]);
    $session = new Store('chat-current-key-history', new ArraySessionHandler(120));
    $session->start();
    $session->put(ResolveChatOwner::SESSION_KEY, $rawToken);
    $request = Request::create('/chat/conversations', 'POST');
    $request->setLaravelSession($session);

    config()->set('app.key', $newAppKey);
    config()->set('app.previous_keys', [$oldAppKey]);

    app(ResolveChatOwner::class)->forRequest($request);

    expect($history->fresh()->guest_key)->toBe($newGuestKey)
        ->and($history->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});
