<?php

use App\Actions\Cart\AcquireActiveCart;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $owner = CartOwner::guest((string) $argv[1]);
    $publicId = (string) $app->make(AcquireActiveCart::class)->execute($owner)->public_id;
} catch (Throwable $failure) {
    fwrite(STDERR, 'Concurrent guest cart acquisition failed.'.PHP_EOL);
    fwrite(STDERR, $failure::class.': '.$failure->getMessage().PHP_EOL);
    fwrite(STDERR, $failure->getTraceAsString().PHP_EOL);

    exit(1);
}

echo $publicId;
