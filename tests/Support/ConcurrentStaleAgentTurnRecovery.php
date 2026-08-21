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
    if (isset($argv[3]) && $argv[3] !== '') {
        Carbon::setTestNow((string) $argv[3]);
    }

    config()->set('ai-assistant.stale_turn_seconds', 60);
    $paused = false;
    $readyPath = (string) ($argv[1] ?? '');
    $releasePath = (string) ($argv[2] ?? '');

    if ($readyPath !== '' && $releasePath !== '') {
        DB::listen(function (QueryExecuted $query) use (&$paused, $readyPath, $releasePath): void {
            $sql = strtolower($query->sql);

            if ($paused || ! str_contains($sql, 'agent_turns')) {
                return;
            }

            $paused = true;
            file_put_contents($readyPath, 'ready');
            $deadline = microtime(true) + 20;

            while (! file_exists($releasePath)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting to release stale turn recovery.');
                }

                usleep(25_000);
            }
        });
    }

    Artisan::call('agent:recover-stale-turns');
    echo Artisan::output();
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent stale agent turn recovery failed.');

    exit(1);
}
