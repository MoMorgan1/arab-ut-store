<?php

use App\Models\ChatConversation;
use App\Models\User;

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

test('422 validation error responses receive no-store private cache control header', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    $response = $this->actingAs($user)->postJson(route('chat.messages.store', [
        'conversation' => $conversation->public_id,
    ]), [
        'content' => '',
    ]);

    $response->assertStatus(422);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('404 disabled chat responses receive no-store private cache control header', function () {
    config()->set('chat.enabled', false);

    $response = $this->getJson(route('chat.conversations.show', [
        'conversation' => '01M00000000000000000000000',
    ]));

    $response->assertStatus(404);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
