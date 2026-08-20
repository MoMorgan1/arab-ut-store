<?php

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

test('concurrent duplicate messages recover one canonical customer and demo reply', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $user = User::factory()->create();
    $originalActivity = now()->subHour()->startOfSecond();
    $conversation = ChatConversation::factory()->forUser($user)->create([
        'last_message_at' => $originalActivity,
    ]);
    $clientMessageId = (string) Str::uuid();
    $barriers = concurrentChatMessageBarrierPaths();
    $audit = concurrentChatMessageAuditNames();
    $first = null;
    $second = null;

    try {
        installConcurrentChatMessageUpdateAudit($audit, $conversation->id);
        $first = concurrentChatMessageProcess(
            $conversation->id,
            $clientMessageId,
            $barriers['first_ready'],
            $barriers['release'],
        );
        $second = concurrentChatMessageProcess(
            $conversation->id,
            $clientMessageId,
            $barriers['second_ready'],
            $barriers['release'],
        );

        $first->start();
        $second->start();
        waitForConcurrentChatMessageBarrier($barriers['first_ready']);
        waitForConcurrentChatMessageBarrier($barriers['second_ready']);
        releaseConcurrentChatMessageWorkers($barriers['release']);
        $first->wait();
        $second->wait();
        refreshConcurrentChatConnection();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput());

        $firstOutput = json_decode(trim($first->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $secondOutput = json_decode(trim($second->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $customer = ChatMessage::query()->where('client_message_id', $clientMessageId)->sole();
        $reply = ChatMessage::query()->where('reply_to_message_id', $customer->id)->sole();
        $activityUpdateCount = (int) DB::table($audit['table'])
            ->where('conversation_id', $conversation->id)
            ->value('update_count');

        expect($firstOutput)->toBe($secondOutput)
            ->and($firstOutput)->toBe([
                'customerPublicId' => $customer->public_id,
                'replyPublicId' => $reply->public_id,
            ])
            ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', 'customer')->count())->toBe(1)
            ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', 'assistant')->count())->toBe(1)
            ->and($activityUpdateCount)->toBe(1)
            ->and($conversation->fresh()->last_message_at->gt($originalActivity))->toBeTrue();
    } finally {
        cleanupConcurrentChatMessageArtifacts($barriers, $audit, $first, $second);
    }
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

function concurrentChatMessageProcess(
    int $conversationId,
    string $clientMessageId,
    string $readyPath,
    string $releasePath,
): Process {
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
        base_path('tests/Support/ConcurrentChatMessage.php'),
        (string) $conversationId,
        $clientMessageId,
        $readyPath,
        $releasePath,
    ], base_path(), concurrentChatDatabaseEnvironment(), timeout: 30);
}

/** @return array{first_ready: string, second_ready: string, release: string} */
function concurrentChatMessageBarrierPaths(): array
{
    $directory = storage_path('framework/testing/chat-message-'.bin2hex(random_bytes(8)));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the concurrent chat message barrier directory.');
    }

    return [
        'first_ready' => $directory.DIRECTORY_SEPARATOR.'first-ready',
        'second_ready' => $directory.DIRECTORY_SEPARATOR.'second-ready',
        'release' => $directory.DIRECTORY_SEPARATOR.'release',
    ];
}

/** @return array{table: string, trigger: string} */
function concurrentChatMessageAuditNames(): array
{
    $suffix = bin2hex(random_bytes(8));

    return [
        'table' => 'chat_message_activity_audit_'.$suffix,
        'trigger' => 'chat_message_activity_trigger_'.$suffix,
    ];
}

/** @param array{table: string, trigger: string} $audit */
function installConcurrentChatMessageUpdateAudit(array $audit, int $conversationId): void
{
    DB::statement(<<<SQL
        CREATE TABLE `{$audit['table']}` (
            conversation_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            update_count INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
        SQL);
    DB::table($audit['table'])->insert([
        'conversation_id' => $conversationId,
        'update_count' => 0,
    ]);
    DB::statement(<<<SQL
        CREATE TRIGGER `{$audit['trigger']}`
        AFTER UPDATE ON `chat_conversations`
        FOR EACH ROW
        UPDATE `{$audit['table']}`
        SET update_count = update_count + 1
        WHERE conversation_id = NEW.id
          AND NOT (NEW.last_message_at <=> OLD.last_message_at)
        SQL);
}

function waitForConcurrentChatMessageBarrier(string $path): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for concurrent chat message worker '.basename($path).'.');
        }

        usleep(25_000);
    }
}

function releaseConcurrentChatMessageWorkers(string $path): void
{
    if (file_put_contents($path, 'release', LOCK_EX) === false) {
        throw new RuntimeException('Unable to release concurrent chat message workers.');
    }
}

/**
 * @param  array{first_ready: string, second_ready: string, release: string}  $barriers
 * @param  array{table: string, trigger: string}  $audit
 */
function cleanupConcurrentChatMessageArtifacts(
    array $barriers,
    array $audit,
    ?Process $first,
    ?Process $second,
): void {
    if (is_dir(dirname($barriers['release'])) && ! file_exists($barriers['release'])) {
        file_put_contents($barriers['release'], 'release', LOCK_EX);
    }

    foreach ([$first, $second] as $process) {
        if ($process?->isRunning() === true) {
            $process->stop(2);
        }
    }

    refreshConcurrentChatConnection();

    try {
        DB::statement("DROP TRIGGER IF EXISTS `{$audit['trigger']}`");
    } finally {
        try {
            DB::statement("DROP TABLE IF EXISTS `{$audit['table']}`");
        } finally {
            cleanupConcurrentChatMessageBarriers($barriers);
        }
    }
}

/** @param array{first_ready: string, second_ready: string, release: string} $barriers */
function cleanupConcurrentChatMessageBarriers(array $barriers): void
{
    foreach ($barriers as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    $directory = dirname($barriers['release']);

    if (is_dir($directory)) {
        rmdir($directory);
    }
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
