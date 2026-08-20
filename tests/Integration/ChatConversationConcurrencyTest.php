<?php

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('a worker timeout still drains its peer and removes readiness artifacts', function () {
    $readinessBarrier = createConcurrentChatReadinessBarrier('cleanup-timeout');
    touch($readinessBarrier['first_ready']);
    touch($readinessBarrier['second_ready']);
    $first = new Process([PHP_BINARY, '-r', 'usleep(1000000);'], timeout: 0.05);
    $second = new Process([PHP_BINARY, '-r', 'usleep(1000000);'], timeout: 0.05);
    $first->start();
    $second->start();
    usleep(100_000);
    $retainedFailure = null;

    try {
        try {
            cleanupConcurrentChatReadinessBarrier($readinessBarrier, $first, $second);
        } catch (ProcessTimedOutException $failure) {
            $retainedFailure = $failure;
        }

        expect($retainedFailure)->toBeInstanceOf(ProcessTimedOutException::class)
            ->and($retainedFailure?->getProcess())->toBe($first)
            ->and($first->isRunning())->toBeFalse()
            ->and($second->isRunning())->toBeFalse()
            ->and(is_dir($readinessBarrier['directory']))->toBeFalse();
    } finally {
        foreach ([$first, $second] as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        foreach (['first_ready', 'second_ready', 'release'] as $pathKey) {
            if (file_exists($readinessBarrier[$pathKey])) {
                unlink($readinessBarrier[$pathKey]);
            }
        }

        if (is_dir($readinessBarrier['directory'])) {
            rmdir($readinessBarrier['directory']);
        }
    }
});

test('an artifact removal failure does not skip later readiness cleanup', function () {
    $readinessBarrier = createConcurrentChatReadinessBarrier('cleanup-artifact-failure');
    mkdir($readinessBarrier['first_ready']);
    touch($readinessBarrier['second_ready']);
    $retainedFailure = null;

    try {
        try {
            cleanupConcurrentChatReadinessBarrier($readinessBarrier, null, null);
        } catch (Throwable $failure) {
            $retainedFailure = $failure;
        }

        expect($retainedFailure)->not->toBeNull()
            ->and(file_exists($readinessBarrier['second_ready']))->toBeFalse()
            ->and(file_exists($readinessBarrier['release']))->toBeFalse();
    } finally {
        foreach (['second_ready', 'release'] as $pathKey) {
            if (file_exists($readinessBarrier[$pathKey])) {
                unlink($readinessBarrier[$pathKey]);
            }
        }

        if (is_dir($readinessBarrier['first_ready'])) {
            rmdir($readinessBarrier['first_ready']);
        }

        if (is_dir($readinessBarrier['directory'])) {
            rmdir($readinessBarrier['directory']);
        }
    }
});

test('concurrent authenticated first acquisitions resolve to one active conversation', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $user = User::factory()->create();
    $readinessBarrier = createConcurrentChatReadinessBarrier('authenticated-acquire');
    $first = null;
    $second = null;

    try {
        $first = concurrentChatAcquireProcess(
            'user',
            (string) $user->id,
            $readinessBarrier['first_ready'],
            $readinessBarrier['release'],
        );
        $second = concurrentChatAcquireProcess(
            'user',
            (string) $user->id,
            $readinessBarrier['second_ready'],
            $readinessBarrier['release'],
        );
        $first->start();
        $second->start();
        waitForConcurrentChatReadiness($readinessBarrier);
        releaseConcurrentChatWorkers($readinessBarrier);
        $first->wait();
        $second->wait();
        refreshConcurrentChatConnection();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and(trim($first->getOutput()))->not->toBe('')
            ->and(trim($second->getOutput()))->toBe(trim($first->getOutput()))
            ->and(ChatConversation::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1);
    } finally {
        try {
            cleanupConcurrentChatReadinessBarrier($readinessBarrier, $first, $second);
        } finally {
            $user->delete();
        }
    }
});

test('concurrent guest first acquisitions resolve to one active conversation', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $guestKey = hash_hmac('sha256', 'concurrent-guest-chat-owner', 'synthetic-concurrency-key');
    deleteConcurrentGuestConversation($guestKey);
    $readinessBarrier = createConcurrentChatReadinessBarrier('guest-acquire');
    $first = null;
    $second = null;

    try {
        $first = concurrentChatAcquireProcess(
            'guest',
            $guestKey,
            $readinessBarrier['first_ready'],
            $readinessBarrier['release'],
        );
        $second = concurrentChatAcquireProcess(
            'guest',
            $guestKey,
            $readinessBarrier['second_ready'],
            $readinessBarrier['release'],
        );
        $first->start();
        $second->start();
        waitForConcurrentChatReadiness($readinessBarrier);
        releaseConcurrentChatWorkers($readinessBarrier);
        $first->wait();
        $second->wait();
        refreshConcurrentChatConnection();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and(trim($first->getOutput()))->not->toBe('')
            ->and(trim($second->getOutput()))->toBe(trim($first->getOutput()))
            ->and(ChatConversation::query()->where('active_owner_key', "guest:{$guestKey}")->count())->toBe(1);
    } finally {
        try {
            cleanupConcurrentChatReadinessBarrier($readinessBarrier, $first, $second);
        } finally {
            deleteConcurrentGuestConversation($guestKey);
        }
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
    $readinessBarrier = createConcurrentChatReadinessBarrier('duplicate-send');
    $first = null;
    $second = null;

    try {
        installChatMessageUpdateAudit();
        $first = concurrentChatMessageProcess(
            (string) $conversation->id,
            $clientMessageId,
            readinessBarrier: [
                'ready' => $readinessBarrier['first_ready'],
                'release' => $readinessBarrier['release'],
            ],
        );
        $second = concurrentChatMessageProcess(
            (string) $conversation->id,
            $clientMessageId,
            readinessBarrier: [
                'ready' => $readinessBarrier['second_ready'],
                'release' => $readinessBarrier['release'],
            ],
        );
        $first->start();
        $second->start();
        waitForConcurrentChatReadiness($readinessBarrier);
        releaseConcurrentChatWorkers($readinessBarrier);
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
            ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', 'assistant')->count())->toBe(1)
            ->and(DB::table('chat_message_update_audits')->where('conversation_id', $conversation->id)->count())->toBe(1);
    } finally {
        try {
            cleanupConcurrentChatReadinessBarrier($readinessBarrier, $first, $second);
        } finally {
            try {
                removeChatMessageUpdateAudit();
            } finally {
                try {
                    $conversation->delete();
                } finally {
                    $user->delete();
                }
            }
        }
    }
});

test('a stale controller-style send rejects after a restart commits before the action lifecycle check', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $barrierKey = 'chat-restart-race-'.Str::uuid();

    try {
        installChatRestartBarrier();
        $send = concurrentChatMessageProcess((string) $conversation->id, (string) Str::uuid(), $barrierKey);
        $restart = concurrentChatRestartProcess((string) $conversation->id, $barrierKey);
        $send->start();
        $restart->start();
        $send->wait();
        $restart->wait();
        refreshConcurrentChatConnection();

        expect($send->isSuccessful())->toBeTrue($send->getErrorOutput())
            ->and($restart->isSuccessful())->toBeTrue($restart->getErrorOutput());

        $sendResult = json_decode($send->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $restartResult = json_decode($restart->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($restartResult['status'])->toBe('restarted')
            ->and($sendResult['status'])->toBe('conversation_closed')
            ->and($conversation->fresh()->status->value)->toBe('closed')
            ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(0);
    } finally {
        removeChatRestartBarrier();
        ChatConversation::query()->where('user_id', $user->id)->delete();
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

function concurrentChatAcquireProcess(
    string $ownerType,
    string $ownerIdentifier,
    string $readyPath,
    string $releasePath,
): Process {
    return new Process([
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentChatAcquire.php'),
        $ownerType,
        $ownerIdentifier,
        $readyPath,
        $releasePath,
    ], base_path(), concurrentChatDatabaseEnvironment(), timeout: 30);
}

/** @param array{ready: string, release: string}|null $readinessBarrier */
function concurrentChatMessageProcess(
    string $conversationId,
    string $clientMessageId,
    ?string $restartBarrierKey = null,
    ?array $readinessBarrier = null,
): Process {
    $command = [
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentChatMessage.php'),
        $conversationId,
        $clientMessageId,
    ];

    if ($restartBarrierKey !== null || $readinessBarrier !== null) {
        $command[] = $restartBarrierKey ?? '';
    }

    if ($readinessBarrier !== null) {
        $command[] = $readinessBarrier['ready'];
        $command[] = $readinessBarrier['release'];
    }

    return new Process($command, base_path(), concurrentChatDatabaseEnvironment(), timeout: 30);
}

/** @return array{directory: string, first_ready: string, second_ready: string, release: string} */
function createConcurrentChatReadinessBarrier(string $scenario): array
{
    $directory = storage_path('framework/testing/chat-'.$scenario.'-'.bin2hex(random_bytes(8)));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create the {$scenario} readiness barrier.");
    }

    return [
        'directory' => $directory,
        'first_ready' => $directory.DIRECTORY_SEPARATOR.'first-ready',
        'second_ready' => $directory.DIRECTORY_SEPARATOR.'second-ready',
        'release' => $directory.DIRECTORY_SEPARATOR.'release',
    ];
}

/** @param array{first_ready: string, second_ready: string} $readinessBarrier */
function waitForConcurrentChatReadiness(array $readinessBarrier): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($readinessBarrier['first_ready']) || ! file_exists($readinessBarrier['second_ready'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for both concurrent chat workers.');
        }

        usleep(25_000);
    }
}

/** @param array{release: string} $readinessBarrier */
function releaseConcurrentChatWorkers(array $readinessBarrier): void
{
    if (! touch($readinessBarrier['release'])) {
        throw new RuntimeException('Unable to release the concurrent chat workers.');
    }
}

/** @param array{directory: string, first_ready: string, second_ready: string, release: string} $readinessBarrier */
function cleanupConcurrentChatReadinessBarrier(
    array $readinessBarrier,
    ?Process $first,
    ?Process $second,
): void {
    $firstFailure = null;

    if (! file_exists($readinessBarrier['release'])) {
        $firstFailure = retainFirstConcurrentChatCleanupFailure(
            $firstFailure,
            fn () => releaseConcurrentChatWorkers($readinessBarrier),
        );
    }

    foreach ([$first, $second] as $process) {
        $firstFailure = retainFirstConcurrentChatCleanupFailure(
            $firstFailure,
            fn () => waitForConcurrentChatProcess($process),
        );
    }

    foreach (['first_ready', 'second_ready', 'release'] as $pathKey) {
        $firstFailure = retainFirstConcurrentChatCleanupFailure(
            $firstFailure,
            fn () => removeConcurrentChatReadinessArtifact($readinessBarrier[$pathKey]),
        );
    }

    $firstFailure = retainFirstConcurrentChatCleanupFailure(
        $firstFailure,
        fn () => removeConcurrentChatReadinessDirectory($readinessBarrier['directory']),
    );

    if ($firstFailure !== null) {
        throw $firstFailure;
    }
}

function retainFirstConcurrentChatCleanupFailure(?Throwable $firstFailure, Closure $cleanupAttempt): ?Throwable
{
    try {
        $cleanupAttempt();
    } catch (Throwable $failure) {
        // Cleanup continues, and the original failure is rethrown after every attempt.
        return $firstFailure ?? $failure;
    }

    return $firstFailure;
}

function removeConcurrentChatReadinessArtifact(string $path): void
{
    if (file_exists($path) && ! unlink($path)) {
        throw new RuntimeException("Unable to remove concurrent chat readiness artifact: {$path}");
    }
}

function removeConcurrentChatReadinessDirectory(string $directory): void
{
    if (is_dir($directory) && ! rmdir($directory)) {
        throw new RuntimeException("Unable to remove concurrent chat readiness directory: {$directory}");
    }
}

function waitForConcurrentChatProcess(?Process $process): void
{
    if ($process?->isRunning()) {
        $process->wait();
    }
}

function concurrentChatRestartProcess(string $conversationId, ?string $barrierKey = null): Process
{
    $command = [
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentChatRestart.php'),
        $conversationId,
    ];

    if ($barrierKey !== null) {
        $command[] = $barrierKey;
    }

    return new Process($command, base_path(), concurrentChatDatabaseEnvironment(), timeout: 30);
}

function installChatMessageUpdateAudit(): void
{
    DB::statement('CREATE TABLE chat_message_update_audits (conversation_id BIGINT UNSIGNED NOT NULL)');
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER chat_message_update_audit
        AFTER UPDATE ON chat_conversations
        FOR EACH ROW
        BEGIN
            INSERT INTO chat_message_update_audits (conversation_id) VALUES (NEW.id);
        END
        SQL);
}

function removeChatMessageUpdateAudit(): void
{
    DB::statement('DROP TRIGGER IF EXISTS chat_message_update_audit');
    DB::statement('DROP TABLE IF EXISTS chat_message_update_audits');
}

function installChatRestartBarrier(): void
{
    DB::statement(<<<'SQL'
        CREATE TABLE chat_restart_barriers (
            race_key VARCHAR(64) PRIMARY KEY,
            sender_ready_at TIMESTAMP NULL,
            restart_committed_at TIMESTAMP NULL
        )
        SQL);
}

function removeChatRestartBarrier(): void
{
    DB::statement('DROP TABLE IF EXISTS chat_restart_barriers');
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
