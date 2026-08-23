<?php

use App\Actions\Checkout\ApplyCoupon;
use App\Actions\Checkout\PlaceOrder;
use App\Models\Cart;
use App\Models\User;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::findOrFail((int) $argv[1]);
$cart = Cart::query()
    ->activeForOwner(CartOwner::user((int) $user->id))
    ->firstOrFail();

try {
    $app->make(ApplyCoupon::class)->apply($cart, (string) $argv[3], $user);
    $result = $app->make(PlaceOrder::class)->execute($user, 'ar', (string) $argv[2]);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent coupon redemption failed.');

    exit(1);
}

echo json_encode([
    'orderId' => $result->order->public_id,
    'discount' => $result->order->discount_halalah,
], JSON_THROW_ON_ERROR);

exit(0);
