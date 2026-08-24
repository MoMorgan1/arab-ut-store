<?php

use App\Enums\Chat\ChatHandoffState;
use App\Enums\Support\SupportTicketStatus;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('concurrent ticket open requests produce exactly one open ticket and idempotent result', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The support ticket open concurrency contract requires MariaDB/MySQL row locking.');
    }

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $barrier = createConcurrentTicketBarrier();
    $first = concurrentTicketOpenProcess($conversation, $user, $barrier['first_ready'], $barrier['release']);
    $second = concurrentTicketOpenProcess($conversation, $user, $barrier['second_ready'], $barrier['release']);

    try {
        $first->start();
        $second->start();
        waitForConcurrentTicketReadiness($barrier);
        releaseConcurrentTicketWorkers($barrier);
        $first->wait();
        $second->wait();
        DB::purge();
        DB::reconnect();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput());

        $firstOutput = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $secondOutput = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $tickets = SupportTicket::query()->where('conversation_id', $conversation->id)->get();

        expect($tickets)->toHaveCount(1);
        $ticket = $tickets->first();

        expect($ticket->status)->toBe(SupportTicketStatus::Open)
            ->and($firstOutput['publicId'])->toBe($ticket->public_id)
            ->and($secondOutput['publicId'])->toBe($ticket->public_id)
            ->and($firstOutput['ticketNumber'])->toBe($ticket->ticket_number)
            ->and($secondOutput['ticketNumber'])->toBe($ticket->ticket_number)
            ->and($conversation->fresh()->handoff_state)->toBe(ChatHandoffState::Requested);
    } finally {
        cleanupConcurrentTicketOpen($barrier, $first, $second);
        $conversation->delete();
        $user->delete();
    }
});

/** @return array{directory:string,first_ready:string,second_ready:string,release:string} */
function createConcurrentTicketBarrier(): array
{
    $directory = storage_path('framework/testing/ticket-open-'.bin2hex(random_bytes(8)));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the concurrent ticket open barrier.');
    }

    return [
        'directory' => $directory,
        'first_ready' => $directory.DIRECTORY_SEPARATOR.'first-ready',
        'second_ready' => $directory.DIRECTORY_SEPARATOR.'second-ready',
        'release' => $directory.DIRECTORY_SEPARATOR.'release',
    ];
}

/** @param array{first_ready:string,second_ready:string} $barrier */
function waitForConcurrentTicketReadiness(array $barrier): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($barrier['first_ready']) || ! file_exists($barrier['second_ready'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for concurrent ticket open workers.');
        }

        usleep(25_000);
    }
}

/** @param array{release:string} $barrier */
function releaseConcurrentTicketWorkers(array $barrier): void
{
    if (! touch($barrier['release'])) {
        throw new RuntimeException('Unable to release concurrent ticket open workers.');
    }
}

function concurrentTicketOpenProcess(
    ChatConversation $conversation,
    User $user,
    string $readyPath,
    string $releasePath,
): Process {
    return new Process([
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentSupportTicketOpen.php'),
        (string) $conversation->id,
        (string) $user->id,
        $readyPath,
        $releasePath,
    ], base_path(), concurrentTicketDatabaseEnvironment(), timeout: 30);
}

/** @param array{directory:string,first_ready:string,second_ready:string,release:string} $barrier */
function cleanupConcurrentTicketOpen(array $barrier, Process $first, Process $second): void
{
    $firstFailure = null;

    if (! file_exists($barrier['release'])) {
        $firstFailure = retainConcurrentTicketCleanupFailure(
            $firstFailure,
            fn () => releaseConcurrentTicketWorkers($barrier),
        );
    }

    foreach ([$first, $second] as $process) {
        $firstFailure = retainConcurrentTicketCleanupFailure($firstFailure, function () use ($process): void {
            if ($process->isRunning()) {
                $process->wait();
            }
        });
    }

    foreach (['first_ready', 'second_ready', 'release'] as $pathKey) {
        $firstFailure = retainConcurrentTicketCleanupFailure($firstFailure, function () use ($barrier, $pathKey): void {
            if (file_exists($barrier[$pathKey]) && ! unlink($barrier[$pathKey])) {
                throw new RuntimeException('Unable to remove a concurrent ticket open artifact.');
            }
        });
    }

    $firstFailure = retainConcurrentTicketCleanupFailure($firstFailure, function () use ($barrier): void {
        if (is_dir($barrier['directory']) && ! rmdir($barrier['directory'])) {
            throw new RuntimeException('Unable to remove the concurrent ticket open barrier.');
        }
    });

    if ($firstFailure !== null) {
        throw $firstFailure;
    }
}

function retainConcurrentTicketCleanupFailure(?Throwable $firstFailure, Closure $cleanup): ?Throwable
{
    try {
        $cleanup();
    } catch (Throwable $failure) {
        return $firstFailure ?? $failure;
    }

    return $firstFailure;
}

/** @return array<string, string> */
function concurrentTicketDatabaseEnvironment(): array
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
