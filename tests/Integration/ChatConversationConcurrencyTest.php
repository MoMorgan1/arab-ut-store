<?php

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('concurrent authenticated first acquisitions resolve to one active conversation', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $user = User::factory()->create();

    try {
        $first = concurrentChatAcquireProcess('user', (string) $user->id);
        $second = concurrentChatAcquireProcess('user', (string) $user->id);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        refreshConcurrentChatConnection();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and(trim($first->getOutput()))->not->toBe('')
            ->and(trim($second->getOutput()))->toBe(trim($first->getOutput()))
            ->and(ChatConversation::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1);
    } finally {
        $user->delete();
    }
});

test('concurrent guest first acquisitions resolve to one active conversation', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $guestKey = hash_hmac('sha256', 'concurrent-guest-chat-owner', 'synthetic-concurrency-key');
    deleteConcurrentGuestConversation($guestKey);

    try {
        $first = concurrentChatAcquireProcess('guest', $guestKey);
        $second = concurrentChatAcquireProcess('guest', $guestKey);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        refreshConcurrentChatConnection();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and(trim($first->getOutput()))->not->toBe('')
            ->and(trim($second->getOutput()))->toBe(trim($first->getOutput()))
            ->and(ChatConversation::query()->where('active_owner_key', "guest:{$guestKey}")->count())->toBe(1);
    } finally {
        deleteConcurrentGuestConversation($guestKey);
    }
});

test('concurrent duplicate messages replay the canonical customer and demo reply', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create([
        'last_message_at' => now()->subMinute(),
    ]);
    $clientMessageId = (string) Str::uuid();

    try {
        $first = concurrentChatMessageProcess((string) $conversation->id, $clientMessageId);
        $second = concurrentChatMessageProcess((string) $conversation->id, $clientMessageId);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        refreshConcurrentChatConnection();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput());

        $firstResult = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $secondResult = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($firstResult)->toBe($secondResult)
            ->and($firstResult['customerPublicId'])->not->toBe('')
            ->and($firstResult['replyPublicId'])->not->toBe('')
            ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', 'customer')->count())->toBe(1)
            ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', 'assistant')->count())->toBe(1);
    } finally {
        $conversation->delete();
        $user->delete();
    }
});

function supportsConcurrentChatLocking(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true);
}

function refreshConcurrentChatConnection(): void
{
    DB::purge();
    DB::reconnect();
}

function deleteConcurrentGuestConversation(string $guestKey): void
{
    ChatConversation::query()
        ->whereNull('user_id')
        ->where('guest_key', $guestKey)
        ->delete();
}

function concurrentChatAcquireProcess(string $ownerType, string $ownerIdentifier): Process
{
    return new Process([
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentChatAcquire.php'),
        $ownerType,
        $ownerIdentifier,
    ], base_path(), concurrentChatDatabaseEnvironment(), timeout: 30);
}

function concurrentChatMessageProcess(string $conversationId, string $clientMessageId): Process
{
    return new Process([
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentChatMessage.php'),
        $conversationId,
        $clientMessageId,
    ], base_path(), concurrentChatDatabaseEnvironment(), timeout: 30);
}

/** @return array<string, string> */
function concurrentChatDatabaseEnvironment(): array
{
    $connection = (string) config('database.default');
    $database = config("database.connections.{$connection}");

    return [
        'APP_ENV' => 'testing',
        'DB_URL' => '',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) $database['host'],
        'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => (string) $database['database'],
        'DB_USERNAME' => (string) $database['username'],
        'DB_PASSWORD' => (string) $database['password'],
    ];
}
