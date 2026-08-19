<?php

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Support\Str;

test('conversation creation fails if both user_id and guest_key are null', function () {
    expect(function () {
        ChatConversation::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => null,
            'guest_key' => null,
            'status' => 'open',
            'locale' => 'ar',
        ]);
    })->toThrow(InvalidArgumentException::class);
});

test('conversation creation fails if both user_id and guest_key are non-null', function () {
    $user = User::factory()->create();

    expect(function () use ($user) {
        ChatConversation::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'guest_key' => str_repeat('a', 64),
            'status' => 'open',
            'locale' => 'ar',
        ]);
    })->toThrow(InvalidArgumentException::class);
});

test('conversation creation succeeds for valid user ownership or guest ownership', function () {
    $user = User::factory()->create();

    $userConv = ChatConversation::query()->create([
        'public_id' => (string) Str::ulid(),
        'user_id' => $user->id,
        'guest_key' => null,
        'status' => 'open',
        'locale' => 'ar',
    ]);
    expect($userConv->exists)->toBeTrue();

    $guestConv = ChatConversation::query()->create([
        'public_id' => (string) Str::ulid(),
        'user_id' => null,
        'guest_key' => str_repeat('b', 64),
        'status' => 'open',
        'locale' => 'ar',
    ]);
    expect($guestConv->exists)->toBeTrue();
});
