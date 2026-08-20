<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    Carbon::setTestNow((string) $argv[3]);
    $paused = false;

    DB::listen(function (QueryExecuted $query) use (&$paused, $argv): void {
        $sql = strtolower($query->sql);

        if ($paused || ! str_contains($sql, 'chat_conversations')
            || ! str_contains($sql, 'closed_at')) {
            return;
        }

        $paused = true;
        file_put_contents((string) $argv[1], 'ready');
        $deadline = microtime(true) + 20;

        while (! file_exists((string) $argv[2])) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting to release chat maintenance.');
            }

            usleep(25_000);
        }
    });

    Artisan::call('chat:maintain-conversations');
    echo Artisan::output();
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat maintenance failed.');

    exit(1);
}
