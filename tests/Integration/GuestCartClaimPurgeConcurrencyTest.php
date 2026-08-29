<?php

use App\Actions\Cart\AcquireActiveCart;
use App\Actions\Cart\PurgeGuestCartClaims;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('purge skips a marker locked by the production owner boundary', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The locked purge contract requires MariaDB/MySQL skip-locked semantics.');
    }

    $guestHmac = hash_hmac('sha256', bin2hex(random_bytes(16)), 'claim-purge-concurrency');
    $owner = CartOwner::guest($guestHmac);
    $cart = app(AcquireActiveCart::class)->execute($owner);
    // Retention is configurable and derived from SESSION_LIFETIME as well, so a
    // fixed two days is only stale under the 24-hour default. .env.example ships
    // 72 hours, which left the marker ineligible and the purge a silent no-op.
    $retentionHours = (int) config('coins.cart.guest_claim_retention_hours');
    DB::table('guest_cart_claims')
        ->where('guest_session_hmac', $guestHmac)
        ->update(['updated_at' => now()->subHours($retentionHours + 1)]);
    $barrierDirectory = storage_path('framework/testing/claim-purge-'.bin2hex(random_bytes(8)));

    if (! mkdir($barrierDirectory, 0777, true) && ! is_dir($barrierDirectory)) {
        throw new RuntimeException('Unable to create the claim purge barrier directory.');
    }

    $readyPath = $barrierDirectory.DIRECTORY_SEPARATOR.'ready';
    $releasePath = $barrierDirectory.DIRECTORY_SEPARATOR.'release';
    $locker = guestClaimMarkerLockProcess($guestHmac, $readyPath, $releasePath);
    $locker->start();
    waitForGuestClaimPurgeBarrier($readyPath);

    $purgedWhileLocked = app(PurgeGuestCartClaims::class)->execute();
    $existsWhileLocked = DB::table('guest_cart_claims')
        ->where('guest_session_hmac', $guestHmac)
        ->exists();
    touch($releasePath);
    $locker->wait();
    $purgedAfterRelease = app(PurgeGuestCartClaims::class)->execute();
    $existsAfterRelease = DB::table('guest_cart_claims')
        ->where('guest_session_hmac', $guestHmac)
        ->exists();
    $cartAfterPurge = $cart->fresh();

    foreach ([$readyPath, $releasePath] as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
    rmdir($barrierDirectory);

    expect($purgedWhileLocked)->toBe(0)
        ->and($existsWhileLocked)->toBeTrue()
        ->and($locker->isSuccessful())->toBeTrue($locker->getErrorOutput())
        ->and($purgedAfterRelease)->toBe(1)
        ->and($existsAfterRelease)->toBeFalse()
        ->and($cartAfterPurge)->not->toBeNull();
});

function guestClaimMarkerLockProcess(string $guestHmac, string $readyPath, string $releasePath): Process
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
        base_path('tests/Support/ConcurrentGuestCartClaimLock.php'),
        $guestHmac,
        $readyPath,
        $releasePath,
    ], base_path(), guestClaimPurgeDatabaseEnvironment(), timeout: 30);
}

function waitForGuestClaimPurgeBarrier(string $path): void
{
    $deadline = microtime(true) + 20;

    while (! file_exists($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the claim purge lock barrier.');
        }

        usleep(25_000);
    }
}

/** @return array<string, string> */
function guestClaimPurgeDatabaseEnvironment(): array
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
