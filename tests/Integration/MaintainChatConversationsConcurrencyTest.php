<?php

use App\Actions\Chat\ClaimGuestChatConversations;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

test('maintenance does not delete an expired guest candidate claimed during its chunk', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The claimed-guest retention race requires MariaDB/MySQL concurrent connections.');
    }

    Carbon::setTestNow('2026-08-20 12:00:00');
    config()->set('chat.guest_retention_hours', 48);
    $guestKey = hash('sha256', 'maintenance-claimed-guest-race');
    $conversation = ChatConversation::factory()->forGuest($guestKey)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subHours(48),
    )->create();
    $message = ChatMessage::factory()->create(['conversation_id' => $conversation->id]);
    $barrierDirectory = storage_path('framework/testing/chat-maintenance-'.bin2hex(random_bytes(8)));

    if (! mkdir($barrierDirectory, 0777, true) && ! is_dir($barrierDirectory)) {
        throw new RuntimeException('Unable to create the chat maintenance barrier directory.');
    }

    $readyPath = $barrierDirectory.DIRECTORY_SEPARATOR.'ready';
    $releasePath = $barrierDirectory.DIRECTORY_SEPARATOR.'release';
    $maintainer = concurrentChatMaintenanceProcess($readyPath, $releasePath, '2026-08-20 12:00:00');
    $user = null;

    try {
        $maintainer->start();
        waitForChatMaintenanceBarrier($readyPath);

        $user = User::factory()->create();
        app(ClaimGuestChatConversations::class)->execute([ChatOwner::guest($guestKey)], $user, null);
        touch($releasePath);
        $maintainer->wait();
        refreshChatMaintenanceConnection();

        expect($maintainer->isSuccessful())->toBeTrue($maintainer->getErrorOutput())
            ->and($maintainer->getOutput())->toContain('Deleted 0 expired conversation(s).')
            ->and($conversation->fresh()->user_id)->toBe($user->id)
            ->and($conversation->fresh()->guest_key)->toBeNull()
            ->and($message->fresh())->not->toBeNull();
    } finally {
        try {
            if (! file_exists($releasePath)) {
                touch($releasePath);
            }

            if ($maintainer->isStarted()) {
                $maintainer->wait();
            }
        } finally {
            try {
                refreshChatMaintenanceConnection();

                try {
                    ChatConversation::query()->whereKey($conversation->id)->delete();
                } finally {
                    if ($user instanceof User) {
                        $user->delete();
                    }
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

function concurrentChatMaintenanceProcess(string $readyPath, string $releasePath, string $testNow): Process
{
    return new Process([
        PHP_BINARY,
        '-d', 'extension_dir='.ini_get('extension_dir'),
        '-d', 'extension=openssl',
        '-d', 'extension=mbstring',
        '-d', 'extension=pdo_mysql',
        base_path('tests/Support/ConcurrentChatConversationMaintenance.php'),
        $readyPath,
        $releasePath,
        $testNow,
    ], base_path(), concurrentChatMaintenanceDatabaseEnvironment(), timeout: 30);
}

function waitForChatMaintenanceBarrier(string $path): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the chat maintenance selection barrier.');
        }

        usleep(25_000);
    }
}

function refreshChatMaintenanceConnection(): void
{
    DB::purge();
    DB::reconnect();
}

/** @return array<string, string> */
function concurrentChatMaintenanceDatabaseEnvironment(): array
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
