<?php

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

test('maintenance and stale recovery racing on the same candidate cannot corrupt deletion or transition', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The stale recovery and maintenance race requires MariaDB/MySQL concurrent connections.');
    }

    Carbon::setTestNow('2026-08-20 12:00:00');
    config()->set('chat.guest_retention_days', 30);
    config()->set('ai-assistant.stale_turn_seconds', 60);

    $guestKey = hash('sha256', 'stale-recovery-maintenance-race');
    $conversation = ChatConversation::factory()->forGuest($guestKey)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(30),
    )->create(['last_message_at' => now()->subDays(30)]);

    $turn = AgentTurn::factory()->running()->for($conversation, 'conversation')->create([
        'updated_at' => now()->subSeconds(70),
    ]);
    $run = AgentRun::factory()->running()->for($turn, 'turn')->create([
        'updated_at' => now()->subSeconds(70),
    ]);

    $barrierDirectory = storage_path('framework/testing/stale-recovery-'.bin2hex(random_bytes(8)));

    if (! mkdir($barrierDirectory, 0777, true) && ! is_dir($barrierDirectory)) {
        throw new RuntimeException('Unable to create the stale recovery barrier directory.');
    }

    $readyPath = $barrierDirectory.DIRECTORY_SEPARATOR.'ready';
    $releasePath = $barrierDirectory.DIRECTORY_SEPARATOR.'release';
    $staleRecoverer = concurrentStaleRecoveryProcess($readyPath, $releasePath, '2026-08-20 12:00:00');

    try {
        $staleRecoverer->start();
        waitForStaleRecoveryBarrier($readyPath);

        // While stale recovery is paused after candidate query, run chat maintenance
        Artisan::call('chat:maintain-conversations');

        // Release the stale recovery process
        touch($releasePath);
        $staleRecoverer->wait();
        refreshStaleRecoveryConnection();

        expect($staleRecoverer->isSuccessful())->toBeTrue($staleRecoverer->getErrorOutput())
            ->and($staleRecoverer->getOutput())->toContain('Recovered 1 stale agent turn(s).');

        // Turn and run must be cleanly failed with StaleTurnRecovered
        $freshTurn = AgentTurn::query()->find($turn->id);
        $freshRun = AgentRun::query()->find($run->id);

        expect($freshTurn)->not->toBeNull()
            ->and($freshTurn->status)->toBe(AgentTurnStatus::Failed)
            ->and($freshTurn->terminal_error_code)->toBe(AgentErrorCode::StaleTurnRecovered)
            ->and($freshRun)->not->toBeNull()
            ->and($freshRun->status)->toBe(AgentRunStatus::Failed)
            ->and($freshRun->error_code)->toBe(AgentErrorCode::StaleTurnRecovered);

        // Now that the turn is terminal, running chat maintenance purges the expired conversation
        Artisan::call('chat:maintain-conversations');
        refreshStaleRecoveryConnection();

        expect(ChatConversation::query()->find($conversation->id))->toBeNull()
            ->and(AgentTurn::query()->find($turn->id))->toBeNull()
            ->and(AgentRun::query()->find($run->id))->toBeNull();
    } finally {
        try {
            if (! file_exists($releasePath)) {
                touch($releasePath);
            }

            if ($staleRecoverer->isStarted()) {
                $staleRecoverer->wait();
            }
        } finally {
            try {
                refreshStaleRecoveryConnection();

                try {
                    ChatConversation::query()->whereKey($conversation->id)->delete();
                } catch (Throwable) {
                }
            } finally {
                foreach ([$readyPath, $releasePath] as $path) {
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }

                if (is_dir($barrierDirectory)) {
                    rmdir($barrierDirectory);
                }
            }
        }
    }
});

function concurrentStaleRecoveryProcess(string $readyPath, string $releasePath, string $testNow): Process
{
    return new Process([
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentStaleAgentTurnRecovery.php'),
        $readyPath,
        $releasePath,
        $testNow,
    ], base_path(), concurrentStaleRecoveryDatabaseEnvironment(), timeout: 30);
}

function waitForStaleRecoveryBarrier(string $path): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the stale recovery selection barrier.');
        }

        usleep(25_000);
    }
}

function refreshStaleRecoveryConnection(): void
{
    DB::purge();
    DB::reconnect();
}

/** @return array<string, string> */
function concurrentStaleRecoveryDatabaseEnvironment(): array
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
