<?php

use App\Actions\Cart\AddCoinsToCart;
use App\Models\User;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$user = User::findOrFail((int) $argv[1]);
// The platform picks the variant, so a caller that wants two distinct lines
// passes two platforms. Defaults to the same-variant case. PC carries no
// delivery mode: the quote rejects a platform and a delivery that disagree.
$platform = $argv[3] ?? 'playstation';
try {
    $result = $app->make(AddCoinsToCart::class)->execute(CartOwner::user((int) $user->id), [
        'platform' => $platform,
        ...($platform === 'pc' ? [] : ['delivery' => 'normal']),
        'quantity' => 100_000,
        'credentials' => [
            'ea_email' => 'concurrency-sentinel@example.test',
            'ea_password' => 'Concurrency Password Sentinel',
            'backup_codes' => ['83000001', '83000002', '83000003'],
        ],
    ], (string) $argv[2], 'ar');
} catch (Throwable $failure) {
    fwrite(STDERR, 'Concurrent Coins addition failed.'.PHP_EOL);
    fwrite(STDERR, $failure::class.': '.$failure->getMessage().PHP_EOL);
    fwrite(STDERR, $failure->getTraceAsString().PHP_EOL);

    exit(1);
}

echo json_encode($result, JSON_THROW_ON_ERROR);

exit($result['status'] === 201 ? 0 : 1);
