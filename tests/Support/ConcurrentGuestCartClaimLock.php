<?php

use App\Actions\Cart\LockGuestCartClaims;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    DB::transaction(function () use ($app, $argv): void {
        $app->make(LockGuestCartClaims::class)->execute([
            CartOwner::guest((string) $argv[1]),
        ]);
        file_put_contents((string) $argv[2], 'ready');
        $deadline = microtime(true) + 20;

        while (! file_exists((string) $argv[3])) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting to release the claim marker lock.');
            }

            usleep(25_000);
        }
    });
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent guest claim marker lock failed.');

    exit(1);
}

echo 'locked';
