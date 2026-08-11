<?php

use App\Actions\Cart\AddCoinsToCart;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$stage = 'preparing';

try {
    $owner = CartOwner::guest((string) $argv[1]);
    $startedPath = $argv[5] ?? null;

    if (is_string($startedPath) && $startedPath !== '') {
        file_put_contents($startedPath, 'started');
    }

    $add = function () use ($app, $owner, $argv, &$stage): array {
        $stage = 'adding';
        $result = $app->make(AddCoinsToCart::class)->execute($owner, [
            'platform' => 'playstation',
            'delivery' => 'normal',
            'quantity' => 100_000,
            'credentials' => [
                'ea_email' => 'guest-concurrency@example.test',
                'ea_password' => 'Guest Concurrency Password Sentinel',
                'backup_codes' => ['84000001', '84000002', '84000003'],
            ],
        ], (string) $argv[2], 'ar');
        $readyPath = $argv[3] ?? null;
        $releasePath = $argv[4] ?? null;

        if (! is_string($readyPath) || $readyPath === ''
            || ! is_string($releasePath) || $releasePath === '') {
            return $result;
        }

        $stage = 'waiting';
        file_put_contents($readyPath, 'ready');
        $deadline = microtime(true) + 20;

        while (! file_exists($releasePath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting to release the guest addition.');
            }

            usleep(25_000);
        }

        return $result;
    };

    $result = ($argv[3] ?? '') !== '' && ($argv[4] ?? '') !== ''
        ? DB::transaction($add)
        : $add();
} catch (Throwable $failure) {
    fwrite(STDERR, sprintf(
        'Concurrent guest Coins addition failed during %s (%s, %s).',
        $stage,
        $failure::class,
        (string) $failure->getCode(),
    ));

    exit(1);
}

echo json_encode($result, JSON_THROW_ON_ERROR);

exit($result['status'] === 201 ? 0 : 1);
