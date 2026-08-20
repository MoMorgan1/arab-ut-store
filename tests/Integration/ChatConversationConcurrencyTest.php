<?php

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('concurrent authenticated first acquisitions resolve to one open conversation', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $user = User::factory()->create();
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
        ->and(ChatConversation::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1)
        ->and(ChatConversation::query()->where('user_id', $user->id)->open()->count())->toBe(1);
});

test('concurrent guest first acquisitions resolve to one open conversation', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $guestKey = hash_hmac('sha256', bin2hex(random_bytes(32)), 'chat-concurrency-owner');
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
        ->and(ChatConversation::query()->where('active_owner_key', "guest:{$guestKey}")->count())->toBe(1)
        ->and(ChatConversation::query()->where('guest_key', $guestKey)->open()->count())->toBe(1);
});

function supportsConcurrentChatLocking(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true);
}

function concurrentChatAcquireProcess(string $ownerType, string $ownerId): Process
{
    return new Process([
        PHP_BINARY,
        '-d',
        'extension_dir='.ini_get('extension_dir'),
        '-d',
        'extension=openssl',
        '-d',
        'extension=mbstring',
        '-d',
        'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentChatAcquire.php'),
        $ownerType,
        $ownerId,
    ], base_path(), concurrentChatDatabaseEnvironment(), timeout: 30);
}

function refreshConcurrentChatConnection(): void
{
    DB::purge();
    DB::reconnect();
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
