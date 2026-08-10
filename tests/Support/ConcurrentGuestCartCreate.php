<?php

use App\Models\Cart;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $owner = CartOwner::guest((string) $argv[1]);
    $publicId = DB::transaction(function () use ($owner): string {
        DB::table('carts')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'user_id' => null,
            'session_key' => $owner->sessionKey(),
            'status' => 'active',
            'currency' => 'SAR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (string) Cart::query()
            ->activeForOwner($owner)
            ->lockForUpdate()
            ->sole()
            ->public_id;
    }, attempts: 3);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent guest cart acquisition failed.');

    exit(1);
}

echo $publicId;
