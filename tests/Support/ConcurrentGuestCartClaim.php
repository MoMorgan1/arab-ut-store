<?php

use App\Actions\Cart\ClaimGuestCart;
use App\Models\User;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $user = User::query()->findOrFail((int) $argv[2]);
    $guestOwners = array_map(
        fn (string $guestHmac): CartOwner => CartOwner::guest($guestHmac),
        explode(',', (string) $argv[1]),
    );
    $startedPath = $argv[5] ?? null;

    if (is_string($startedPath) && $startedPath !== '') {
        file_put_contents($startedPath, 'started');
    }

    $claim = function () use ($app, $guestOwners, $user, $argv): void {
        $app->make(ClaimGuestCart::class)->execute($guestOwners, $user);
        $readyPath = $argv[3] ?? null;
        $releasePath = $argv[4] ?? null;

        if (! is_string($readyPath) || $readyPath === ''
            || ! is_string($releasePath) || $releasePath === '') {
            return;
        }

        file_put_contents($readyPath, 'ready');
        $deadline = microtime(true) + 20;

        while (! file_exists($releasePath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting to release the guest claim.');
            }

            usleep(25_000);
        }
    };

    if (($argv[3] ?? '') !== '' && ($argv[4] ?? '') !== '') {
        DB::transaction($claim);
    } else {
        $claim();
    }
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent guest cart claim failed.');

    exit(1);
}

echo 'claimed';
