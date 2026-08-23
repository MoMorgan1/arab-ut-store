<?php

use App\Admin\Actions\TransitionAdminOrder;
use App\Enums\OrderStatus;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$stage = 'preparing';

try {
    $admin = User::query()->findOrFail((int) $argv[1]);
    $orderPublicId = (string) $argv[2];
    $expectedStatus = OrderStatus::from((string) $argv[3]);
    $readyPath = $argv[4] ?? null;
    $releasePath = $argv[5] ?? null;

    if (is_string($readyPath) && $readyPath !== '' && is_string($releasePath) && $releasePath !== '') {
        file_put_contents($readyPath, 'ready');

        $deadline = microtime(true) + 20;

        while (! file_exists($releasePath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for the concurrent completion release.');
            }

            usleep(25_000);
        }
    }

    $stage = 'transitioning';
    $order = $app->make(TransitionAdminOrder::class)->execute(
        $admin,
        $orderPublicId,
        OrderStatus::Completed,
        $expectedStatus,
    );

    echo json_encode(['publicId' => (string) $order->public_id], JSON_THROW_ON_ERROR);
} catch (Throwable $failure) {
    fwrite(STDERR, sprintf(
        'Concurrent loyalty completion failed during %s (%s).',
        $stage,
        $failure->getMessage(),
    ));

    exit(1);
}
