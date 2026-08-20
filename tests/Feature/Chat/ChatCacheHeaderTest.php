<?php

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

test('200 success responses receive no-store private cache control header', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    $response = $this->actingAs($user)->getJson(route('chat.conversations.show', [
        'conversation' => $conversation->public_id,
    ]));

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('404 not found responses receive no-store private cache control header', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson(route('chat.conversations.show', [
        'conversation' => '01M00000000000000000000000',
    ]));

    $response->assertNotFound();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('validation errors use the exact localized private chat envelope', function (string $locale, string $message) {
    config()->set('store.default_locale', $locale);

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    $response = $this->actingAs($user)->postJson(route('chat.messages.store', [
        'conversation' => $conversation->public_id,
    ]), [
        'content' => '',
    ]);

    $response->assertStatus(422)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'error' => [
                'code' => 'validation_error',
                'message' => $message,
                'details' => [],
            ],
        ]);
    expect(json_decode($response->getContent())->error->details)->toEqual((object) []);
})->with([
    'English' => ['en', 'The submitted chat data is invalid.'],
    'Arabic' => ['ar', 'بيانات الشات المرسلة غير صالحة.'],
]);

test('message throttling uses the exact localized private chat envelope', function (string $locale, string $message) {
    config()->set('store.default_locale', $locale);

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    RateLimiter::clear('chat-messages:'.$user->id);
    RateLimiter::clear('chat-messages-ip:127.0.0.1');

    for ($requestNumber = 1; $requestNumber <= 30; $requestNumber++) {
        $this->actingAs($user)
            ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
                'content' => 'Rate-limit request '.$requestNumber,
                'client_message_id' => (string) Str::uuid(),
            ])
            ->assertCreated();
    }

    $response = $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => 'One request too many',
            'client_message_id' => (string) Str::uuid(),
        ]);

    $response->assertTooManyRequests()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'error' => [
                'code' => 'rate_limited',
                'message' => $message,
                'details' => [],
            ],
        ]);
    expect(json_decode($response->getContent())->error->details)->toEqual((object) []);
})->with([
    'English' => ['en', 'Too many chat requests. Please try again shortly.'],
    'Arabic' => ['ar', 'طلبات الشات كثيرة الآن. حاول مرة ثانية بعد قليل.'],
]);

test('unexpected chat failures use a localized private envelope without exception text', function (string $locale, string $message) {
    config()->set('store.default_locale', $locale);

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER chat_messages_failure_sentinel
        BEFORE INSERT ON chat_messages
        BEGIN
            SELECT RAISE(ABORT, 'private-message-content-sentinel');
        END
        SQL);

    try {
        $response = $this->actingAs($user)
            ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
                'content' => 'Customer content must stay private',
                'client_message_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(500)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'error' => [
                    'code' => 'chat_unavailable',
                    'message' => $message,
                    'details' => [],
                ],
            ]);
        expect(json_decode($response->getContent())->error->details)->toEqual((object) [])
            ->and($response->getContent())->not->toContain('private-message-content-sentinel')
            ->and($response->getContent())->not->toContain('Customer content must stay private');
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS chat_messages_failure_sentinel');
    }
})->with([
    'English' => ['en', 'Chat is temporarily unavailable. Please try again.'],
    'Arabic' => ['ar', 'الشات غير متاح مؤقتًا. حاول مرة ثانية.'],
]);

test('404 disabled chat responses receive no-store private cache control header', function () {
    config()->set('chat.enabled', false);

    $response = $this->getJson(route('chat.conversations.show', [
        'conversation' => '01M00000000000000000000000',
    ]));

    $response->assertStatus(404);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
