<?php

use App\Actions\Cart\ClaimGuestCart;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $user = User::query()->findOrFail((int) $argv[2]);
    $app->make(ClaimGuestCart::class)->execute((string) $argv[1], $user);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent guest cart claim failed.');

    exit(1);
}

echo 'claimed';
