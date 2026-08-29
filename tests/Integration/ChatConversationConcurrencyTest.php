<?php

use App\Actions\Chat\ClaimGuestChatConversations;
use App\Actions\Chat\CreateChatMessage;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('a worker timeout still drains its peer and removes readiness artifacts', function () {
    $readinessBarrier = createConcurrentChatReadinessBarrier('cleanup-timeout');
    touch($readinessBarrier['first_ready']);
    touch($readinessBarrier['second_ready']);
    $first = new Process([PHP_BINARY, '-r', 'usleep(1000000);'], timeout: null);
    $second = new Process([PHP_BINARY, '-r', 'usleep(1000000);'], timeout: null);
    $retainedFailure = null;

    try {
        $first->start();
        $second->start();
        $first->setTimeout(0.05);
        $second->setTimeout(0.05);
        usleep(100_000);

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

test('a real process consolidates rotated guest open rows without losing pointed continuity', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The rotated-key process contract requires MariaDB/MySQL row locking.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $previousAppKey = 'base64:'.base64_encode(str_repeat('r', 32));
    $currentAppKey = 'base64:'.base64_encode(str_repeat('s', 32));
    $rawToken = str_repeat('c', 64);
    $previousGuestKey = hash_hmac('sha256', $rawToken, $previousAppKey);
    $currentGuestKey = hash_hmac('sha256', $rawToken, $currentAppKey);
    deleteConcurrentGuestConversation($previousGuestKey);
    deleteConcurrentGuestConversation($currentGuestKey);
    $currentOpen = ChatConversation::factory()->forGuest($currentGuestKey)->create([
        'last_message_at' => now(),
    ]);
    $pointedOpen = ChatConversation::factory()->forGuest($previousGuestKey)->create([
        'last_message_at' => now()->subMinute(),
    ]);
    $history = ChatConversation::factory()->forGuest($previousGuestKey)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDay(),
    )->create();
    $messages = collect([$currentOpen, $pointedOpen, $history])
        ->map(fn (ChatConversation $conversation): ChatMessage => ChatMessage::factory()->create([
            'conversation_id' => $conversation->id,
        ]));

    try {
        $process = rotatedGuestChatAcquireProcess(
            $rawToken,
            $currentAppKey,
            $previousAppKey,
            $pointedOpen->public_id,
        );
        $process->run();
        refreshConcurrentChatConnection();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result['publicId'])->toBe($pointedOpen->public_id)
            ->and($result['guestKey'])->toBe($currentGuestKey)
            ->and($pointedOpen->fresh()->status)->toBe(ChatConversationStatus::Open)
            ->and($pointedOpen->fresh()->guest_key)->toBe($currentGuestKey)
            ->and($currentOpen->fresh()->status)->toBe(ChatConversationStatus::Closed)
            ->and($currentOpen->fresh()->close_reason)->toBe(ChatConversationCloseReason::InvariantUpgradeDuplicate)
            ->and($currentOpen->fresh()->guest_key)->toBe($currentGuestKey)
            ->and($history->fresh()->guest_key)->toBe($currentGuestKey)
            ->and(ChatConversation::query()->where('active_owner_key', "guest:{$currentGuestKey}")->count())->toBe(1)
            ->and(ChatMessage::query()->whereIn('id', $messages->pluck('id'))->count())->toBe(3);
    } finally {
        deleteConcurrentGuestConversation($previousGuestKey);
        deleteConcurrentGuestConversation($currentGuestKey);
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
        installChatLifecycleBarrier();
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
        removeChatLifecycleBarrier();
        ChatConversation::query()->where('user_id', $user->id)->delete();
        $user->delete();
    }
});

test('a stale guest send rejects after login claim commits before the action owner check', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    $guestKey = hash_hmac('sha256', bin2hex(random_bytes(32)), 'chat-concurrency-owner');
    $conversation = ChatConversation::factory()->forGuest($guestKey)->create();
    $user = User::factory()->create();
    $barrierKey = 'chat-claim-race-'.Str::uuid();
    $send = null;

    try {
        installChatLifecycleBarrier();
        $send = concurrentChatMessageProcess((string) $conversation->id, (string) Str::uuid(), $barrierKey);
        $send->start();
        waitForChatSenderReady($barrierKey);

        app(ClaimGuestChatConversations::class)->execute(
            [ChatOwner::guest($guestKey)],
            $user,
            $conversation->public_id,
        );
        DB::table('chat_lifecycle_barriers')->where('race_key', $barrierKey)->update([
            'lifecycle_committed_at' => now(),
        ]);

        $send->wait();
        refreshConcurrentChatConnection();

        expect($send->isSuccessful())->toBeTrue($send->getErrorOutput())
            ->and(json_decode($send->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'status' => 'conversation_not_found',
            ])
            ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->exists())->toBeFalse();
    } finally {
        if ($send?->isRunning()) {
            DB::table('chat_lifecycle_barriers')->where('race_key', $barrierKey)->update([
                'lifecycle_committed_at' => now(),
            ]);
            $send->wait();
        }

        removeChatLifecycleBarrier();
        ChatConversation::query()->where('user_id', $user->id)->delete();
        $user->delete();
    }
});

test('duplicate contention recovery cannot replay a message after guest ownership is revoked', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    $guestKey = hash_hmac('sha256', bin2hex(random_bytes(32)), 'chat-contention-owner');
    $conversation = ChatConversation::factory()->forGuest($guestKey)->create();
    $user = User::factory()->create();
    $clientMessageId = (string) Str::uuid();
    $ownershipRevoked = false;

    try {
        installSyntheticChatMessageContentionTrigger();
        Event::listen(TransactionRolledBack::class, function () use (
            &$ownershipRevoked,
            $clientMessageId,
            $conversation,
            $user,
        ): void {
            if ($ownershipRevoked) {
                return;
            }

            $ownershipRevoked = true;
            ChatMessage::factory()->customer()->for($conversation, 'conversation')->create([
                'client_message_id' => $clientMessageId,
                'content' => 'Canonical message created after contention.',
            ]);
            $conversation->update([
                'user_id' => $user->id,
                'guest_key' => null,
            ]);
        });

        expect(fn () => app(CreateChatMessage::class)->execute(
            $conversation,
            'Trigger synthetic client-message contention.',
            $clientMessageId,
            ChatOwner::guest($guestKey),
        ))->toThrow(ModelNotFoundException::class)
            ->and($ownershipRevoked)->toBeTrue();
    } finally {
        Event::forget(TransactionRolledBack::class);
        removeSyntheticChatMessageContentionTrigger();
        ChatConversation::query()->where('user_id', $user->id)->orWhere('guest_key', $guestKey)->delete();
        $user->delete();
    }
});

test('a login claim blocks while a message write holds the conversation row lock', function () {
    if (! supportsConcurrentChatLocking()) {
        $this->markTestSkipped('The concurrency contract requires MariaDB/MySQL row locking.');
    }

    $guestKey = hash_hmac('sha256', bin2hex(random_bytes(32)), 'chat-concurrency-lock-owner');
    $conversation = ChatConversation::factory()->forGuest($guestKey)->create();
    $user = User::factory()->create();
    $clientMessageId = (string) Str::uuid();
    $namedLock = 'chat-message-pause-'.$clientMessageId;
    $send = null;
    $claim = null;
    $namedLockHeld = false;

    try {
        installChatMessageInsertPauseTrigger();
        $namedLockHeld = acquireConcurrentChatNamedLock($namedLock);
        expect($namedLockHeld)->toBeTrue();

        $send = concurrentChatMessageProcess((string) $conversation->id, $clientMessageId);
        $send->start();
        waitForChatMessageInsertPause('chat-message-ready-'.$clientMessageId);

        $claim = concurrentChatClaimProcess($guestKey, (string) $user->id, $conversation->public_id, 1);
        $claim->start();
        $claim->wait();

        expect($claim->isSuccessful())->toBeTrue($claim->getErrorOutput())
            ->and(json_decode($claim->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'status' => 'lock_wait_timeout',
            ])
            ->and($conversation->fresh()->guest_key)->toBe($guestKey);

        releaseConcurrentChatNamedLock($namedLock);
        $namedLockHeld = false;
        $send->wait();
        refreshConcurrentChatConnection();
        app(ClaimGuestChatConversations::class)->execute(
            [ChatOwner::guest($guestKey)],
            $user,
            $conversation->public_id,
        );

        expect($send->isSuccessful())->toBeTrue($send->getErrorOutput())
            ->and(json_decode($send->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('sent')
            ->and($conversation->fresh()->user_id)->toBe($user->id)
            ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
    } finally {
        $cleanupFailure = null;

        if ($namedLockHeld) {
            $cleanupFailure = retainFirstConcurrentChatCleanupFailure(
                $cleanupFailure,
                fn () => releaseConcurrentChatNamedLock($namedLock),
            );
        }

        foreach ([$send, $claim] as $process) {
            $cleanupFailure = retainFirstConcurrentChatCleanupFailure(
                $cleanupFailure,
                fn () => waitForConcurrentChatProcess($process),
            );
        }

        foreach ([
            fn () => removeChatMessageInsertPauseTrigger(),
            fn () => ChatConversation::query()->where('user_id', $user->id)->orWhere('guest_key', $guestKey)->delete(),
            fn () => $user->delete(),
        ] as $cleanupAttempt) {
            $cleanupFailure = retainFirstConcurrentChatCleanupFailure($cleanupFailure, $cleanupAttempt);
        }

        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }
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

function rotatedGuestChatAcquireProcess(
    string $rawToken,
    string $currentAppKey,
    string $previousAppKey,
    string $activePublicId,
): Process {
    return new Process([
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/RotatedGuestChatAcquire.php'),
        $rawToken,
        $currentAppKey,
        $previousAppKey,
        $activePublicId,
    ], base_path(), concurrentChatDatabaseEnvironment(), timeout: 30);
}

/** @param array{ready: string, release: string}|null $readinessBarrier */
function concurrentChatMessageProcess(
    string $conversationId,
    string $clientMessageId,
    ?string $lifecycleBarrierKey = null,
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

    if ($lifecycleBarrierKey !== null || $readinessBarrier !== null) {
        $command[] = $lifecycleBarrierKey ?? '';
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

function concurrentChatClaimProcess(
    string $guestKey,
    string $userId,
    string $activePublicId,
    int $lockWaitTimeout,
): Process {
    return new Process([
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentChatClaim.php'),
        $guestKey,
        $userId,
        $activePublicId,
        (string) $lockWaitTimeout,
    ], base_path(), concurrentChatDatabaseEnvironment(), timeout: 30);
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

function installSyntheticChatMessageContentionTrigger(): void
{
    if (DB::connection()->getDriverName() === 'sqlite') {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_synthetic_chat_message_contention
            BEFORE INSERT ON chat_messages
            FOR EACH ROW
            WHEN NEW.content = 'Trigger synthetic client-message contention.'
            BEGIN
                SELECT RAISE(ABORT, 'uq_chat_messages_client_id');
            END;
            SQL);

        return;
    }

    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_synthetic_chat_message_contention
        BEFORE INSERT ON chat_messages
        FOR EACH ROW
        BEGIN
            IF NEW.content = 'Trigger synthetic client-message contention.' THEN
                SIGNAL SQLSTATE '23000' SET MESSAGE_TEXT = 'uq_chat_messages_client_id';
            END IF;
        END
        SQL);
}

function removeSyntheticChatMessageContentionTrigger(): void
{
    DB::statement('DROP TRIGGER IF EXISTS fail_synthetic_chat_message_contention');
}

function installChatMessageInsertPauseTrigger(): void
{
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER pause_chat_message_insert
        BEFORE INSERT ON chat_messages
        FOR EACH ROW
        BEGIN
            DECLARE acquired_ready_lock INT;
            DECLARE acquired_pause_lock INT;
            IF NEW.client_message_id IS NOT NULL THEN
                SET acquired_ready_lock = GET_LOCK(CONCAT('chat-message-ready-', NEW.client_message_id), 0);
                IF acquired_ready_lock <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unable to signal the chat message insert barrier.';
                END IF;
                SET acquired_pause_lock = GET_LOCK(CONCAT('chat-message-pause-', NEW.client_message_id), 20);
                IF acquired_pause_lock <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Timed out waiting for the chat message insert barrier.';
                END IF;
            END IF;
        END
        SQL);
}

function removeChatMessageInsertPauseTrigger(): void
{
    DB::statement('DROP TRIGGER IF EXISTS pause_chat_message_insert');
}

function waitForChatMessageInsertPause(string $readyLock): void
{
    $deadline = microtime(true) + 20;

    while (DB::selectOne('SELECT IS_USED_LOCK(?) AS connection_id', [$readyLock])->connection_id === null) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the chat message insert barrier.');
        }

        usleep(25_000);
    }
}

function acquireConcurrentChatNamedLock(string $lockName): bool
{
    $row = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired_lock', [$lockName]);

    return (int) $row->acquired_lock === 1;
}

function releaseConcurrentChatNamedLock(string $lockName): void
{
    DB::selectOne('SELECT RELEASE_LOCK(?) AS released_lock', [$lockName]);
}

function installChatLifecycleBarrier(): void
{
    DB::statement(<<<'SQL'
        CREATE TABLE chat_lifecycle_barriers (
            race_key VARCHAR(64) PRIMARY KEY,
            sender_ready_at TIMESTAMP NULL,
            lifecycle_committed_at TIMESTAMP NULL
        )
        SQL);
}

function removeChatLifecycleBarrier(): void
{
    DB::statement('DROP TABLE IF EXISTS chat_lifecycle_barriers');
}

function waitForChatSenderReady(string $barrierKey): void
{
    $deadline = microtime(true) + 20;

    while (! DB::table('chat_lifecycle_barriers')->where('race_key', $barrierKey)->whereNotNull('sender_ready_at')->exists()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the chat sender readiness barrier.');
        }

        usleep(25_000);
    }
}

/** @return array<string, string> */
function concurrentChatDatabaseEnvironment(): array
{
    $connection = (string) config('database.default');
    $database = config("database.connections.{$connection}");

    // Symfony merges this over the parent environment rather than replacing it,
    // so the child otherwise inherits the AI_* keys phpunit.xml putenv()s and
    // resolves Agent mode -- and CreateChatMessage writes a demo reply only when
    // the agent is ineligible. Every worker here exercises the demo lifecycle,
    // so the assistant is pinned off at the boundary instead of relying on
    // whatever the suite or a developer .env happens to hold.
    return [
        'APP_ENV' => 'testing',
        'DB_URL' => '',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) $database['host'],
        'DB_PORT' => (string) $database['port'],
        'DB_DATABASE' => (string) $database['database'],
        'DB_USERNAME' => (string) $database['username'],
        'DB_PASSWORD' => (string) $database['password'],
        'AI_ASSISTANT_ENABLED' => 'false',
        'AI_ASSISTANT_ROLLOUT' => 'disabled',
        'CHAT_DEMO_ASSISTANT' => 'true',
    ];
}
