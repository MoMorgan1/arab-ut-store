<?php

use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('concurrent eligible FIFO claims return one canonical turn and one starter', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The agent claim concurrency contract requires MariaDB/MySQL row locking.');
    }

    $conversation = ChatConversation::factory()->create();
    $message = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create(['created_at' => now()->subSecond()]);
    $barrier = createConcurrentAgentBarrier();
    $first = concurrentAgentClaimProcess($conversation, $barrier['first_ready'], $barrier['release']);
    $second = concurrentAgentClaimProcess($conversation, $barrier['second_ready'], $barrier['release']);

    try {
        $first->start();
        $second->start();
        waitForConcurrentAgentReadiness($barrier);
        releaseConcurrentAgentWorkers($barrier);
        $first->wait();
        $second->wait();
        DB::purge();
        DB::reconnect();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput());
        $claims = collect([$first, $second])->map(
            fn (Process $process): array => json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR),
        );
        $turn = AgentTurn::query()->where('conversation_id', $conversation->id)->sole();

        expect($claims->pluck('publicId')->unique()->values()->all())->toBe([$turn->public_id])
            ->and($claims->where('shouldStart', true)->count())->toBe(1)
            ->and($claims->where('shouldStart', false)->count())->toBe(1)
            ->and($turn->status)->toBe(AgentTurnStatus::Waiting)
            ->and($turn->first_customer_message_id)->toBe($message->id)
            ->and($turn->last_customer_message_id)->toBe($message->id);
    } finally {
        cleanupConcurrentAgentClaim($barrier, $first, $second);
        $conversation->delete();
    }
});

/** @return array{directory:string,first_ready:string,second_ready:string,release:string} */
function createConcurrentAgentBarrier(): array
{
    $directory = storage_path('framework/testing/agent-claim-'.bin2hex(random_bytes(8)));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the concurrent agent claim barrier.');
    }

    return [
        'directory' => $directory,
        'first_ready' => $directory.DIRECTORY_SEPARATOR.'first-ready',
        'second_ready' => $directory.DIRECTORY_SEPARATOR.'second-ready',
        'release' => $directory.DIRECTORY_SEPARATOR.'release',
    ];
}

/** @param array{first_ready:string,second_ready:string} $barrier */
function waitForConcurrentAgentReadiness(array $barrier): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($barrier['first_ready']) || ! file_exists($barrier['second_ready'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for concurrent agent claim workers.');
        }

        usleep(25_000);
    }
}

/** @param array{release:string} $barrier */
function releaseConcurrentAgentWorkers(array $barrier): void
{
    if (! touch($barrier['release'])) {
        throw new RuntimeException('Unable to release concurrent agent claim workers.');
    }
}

function concurrentAgentClaimProcess(
    ChatConversation $conversation,
    string $readyPath,
    string $releasePath,
): Process {
    return new Process([
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentAgentTurnClaim.php'),
        (string) $conversation->id,
        $readyPath,
        $releasePath,
    ], base_path(), concurrentAgentDatabaseEnvironment(), timeout: 30);
}

/** @param array{directory:string,first_ready:string,second_ready:string,release:string} $barrier */
function cleanupConcurrentAgentClaim(array $barrier, Process $first, Process $second): void
{
    $firstFailure = null;

    if (! file_exists($barrier['release'])) {
        $firstFailure = retainConcurrentAgentCleanupFailure(
            $firstFailure,
            fn () => releaseConcurrentAgentWorkers($barrier),
        );
    }

    foreach ([$first, $second] as $process) {
        $firstFailure = retainConcurrentAgentCleanupFailure($firstFailure, function () use ($process): void {
            if ($process->isRunning()) {
                $process->wait();
            }
        });
    }

    foreach (['first_ready', 'second_ready', 'release'] as $pathKey) {
        $firstFailure = retainConcurrentAgentCleanupFailure($firstFailure, function () use ($barrier, $pathKey): void {
            if (file_exists($barrier[$pathKey]) && ! unlink($barrier[$pathKey])) {
                throw new RuntimeException('Unable to remove a concurrent agent claim artifact.');
            }
        });
    }

    $firstFailure = retainConcurrentAgentCleanupFailure($firstFailure, function () use ($barrier): void {
        if (is_dir($barrier['directory']) && ! rmdir($barrier['directory'])) {
            throw new RuntimeException('Unable to remove the concurrent agent claim barrier.');
        }
    });

    if ($firstFailure !== null) {
        throw $firstFailure;
    }
}

function retainConcurrentAgentCleanupFailure(?Throwable $firstFailure, Closure $cleanup): ?Throwable
{
    try {
        $cleanup();
    } catch (Throwable $failure) {
        return $firstFailure ?? $failure;
    }

    return $firstFailure;
}

/** @return array<string, string> */
function concurrentAgentDatabaseEnvironment(): array
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
