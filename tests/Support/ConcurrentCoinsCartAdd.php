<?php

use App\Actions\Cart\AddCoinsToCart;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$user = User::findOrFail((int) $argv[1]);
$result = $app->make(AddCoinsToCart::class)->execute($user, [
    'platform' => 'playstation',
    'delivery' => 'normal',
    'quantity' => 100_000,
    'credentials' => [
        'ea_email' => 'concurrency-sentinel@example.test',
        'ea_password' => 'Concurrency Password Sentinel',
        'backup_codes' => ['83000001', '83000002', '83000003', '83000004', '83000005'],
    ],
], (string) $argv[2], 'ar');

exit($result['status'] === 201 ? 0 : 1);
