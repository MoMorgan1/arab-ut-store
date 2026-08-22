<?php

use App\Enums\AI\AgentTurnStatus;
use App\Enums\Chat\ChatSenderType;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('concurrent finalizers for the same running turn persist one assistant message with no overwrite', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The agent finalization concurrency contract requires MariaDB/MySQL row locking.');
    }

    $conversation = ChatConversation::factory()->create();
    $customerMessage = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()->subSeconds(2)]);
    $turn = AgentTurn::factory()->running()->for($conversation, 'conversation')->create([
        'first_customer_message_id' => $customerMessage->id,
        'last_customer_message_id' => $customerMessage->id,
        'attempt_count' => 1,
    ]);
    $run = AgentRun::factory()->running()->for($turn, 'turn')->create([
        'attempt_number' => 1,
    ]);

    $barrier = createConcurrentFinalizationBarrier();
    $first = concurrentAgentFinalizationProcess($turn, $run, $barrier['first_ready'], $barrier['release'], 'First candidate response.');
    $second = concurrentAgentFinalizationProcess($turn, $run, $barrier['second_ready'], $barrier['release'], 'Second candidate response.');

    try {
        $first->start();
        $second->start();
        waitForConcurrentFinalizationReadiness($barrier);
        releaseConcurrentFinalizationWorkers($barrier);
        $first->wait();
        $second->wait();
        DB::purge();
        DB::reconnect();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput());

        $firstResult = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $secondResult = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $freshTurn = $turn->fresh();
        $assistantMessages = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', ChatSenderType::Assistant)
            ->get();

        expect($assistantMessages)->toHaveCount(1)
            ->and($freshTurn->status)->toBe(AgentTurnStatus::Completed)
            ->and($freshTurn->assistant_message_id)->toBe($assistantMessages->first()->id)
            ->and($firstResult['messageId'])->toBe($assistantMessages->first()->id)
            ->and($secondResult['messageId'])->toBe($assistantMessages->first()->id);
    } finally {
        cleanupConcurrentFinalization($barrier, $first, $second);
        $conversation->delete();
    }
});

/** @return array{directory:string,first_ready:string,second_ready:string,release:string} */
function createConcurrentFinalizationBarrier(): array
{
    $directory = storage_path('framework/testing/agent-finalization-'.bin2hex(random_bytes(8)));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the concurrent agent finalization barrier.');
    }

    return [
        'directory' => $directory,
        'first_ready' => $directory.DIRECTORY_SEPARATOR.'first-ready',
        'second_ready' => $directory.DIRECTORY_SEPARATOR.'second-ready',
        'release' => $directory.DIRECTORY_SEPARATOR.'release',
    ];
}

/** @param array{first_ready:string,second_ready:string} $barrier */
function waitForConcurrentFinalizationReadiness(array $barrier): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($barrier['first_ready']) || ! file_exists($barrier['second_ready'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for concurrent agent finalization workers.');
        }

        usleep(25_000);
    }
}

/** @param array{release:string} $barrier */
function releaseConcurrentFinalizationWorkers(array $barrier): void
{
    if (! touch($barrier['release'])) {
        throw new RuntimeException('Unable to release concurrent agent finalization workers.');
    }
}

function concurrentAgentFinalizationProcess(
    AgentTurn $turn,
    AgentRun $run,
    string $readyPath,
    string $releasePath,
    string $text,
): Process {
    return new Process([
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentAgentTurnFinalization.php'),
        (string) $turn->id,
        (string) $run->id,
        $readyPath,
        $releasePath,
        $text,
    ], base_path(), concurrentFinalizationDatabaseEnvironment(), timeout: 30);
}

/** @param array{directory:string,first_ready:string,second_ready:string,release:string} $barrier */
function cleanupConcurrentFinalization(array $barrier, Process $first, Process $second): void
{
    $firstFailure = null;

    if (! file_exists($barrier['release'])) {
        $firstFailure = retainConcurrentFinalizationCleanupFailure(
            $firstFailure,
            fn () => releaseConcurrentFinalizationWorkers($barrier),
        );
    }

    foreach ([$first, $second] as $process) {
        $firstFailure = retainConcurrentFinalizationCleanupFailure($firstFailure, function () use ($process): void {
            if ($process->isRunning()) {
                $process->wait();
            }
        });
    }

    foreach (['first_ready', 'second_ready', 'release'] as $pathKey) {
        $firstFailure = retainConcurrentFinalizationCleanupFailure($firstFailure, function () use ($barrier, $pathKey): void {
            if (file_exists($barrier[$pathKey]) && ! unlink($barrier[$pathKey])) {
                throw new RuntimeException('Unable to remove a concurrent agent finalization artifact.');
            }
        });
    }

    $firstFailure = retainConcurrentFinalizationCleanupFailure($firstFailure, function () use ($barrier): void {
        if (is_dir($barrier['directory']) && ! rmdir($barrier['directory'])) {
            throw new RuntimeException('Unable to remove the concurrent agent finalization barrier.');
        }
    });

    if ($firstFailure !== null) {
        throw $firstFailure;
    }
}

function retainConcurrentFinalizationCleanupFailure(?Throwable $firstFailure, Closure $cleanup): ?Throwable
{
    try {
        $cleanup();
    } catch (Throwable $failure) {
        return $firstFailure ?? $failure;
    }

    return $firstFailure;
}

/** @return array<string, string> */
function concurrentFinalizationDatabaseEnvironment(): array
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
