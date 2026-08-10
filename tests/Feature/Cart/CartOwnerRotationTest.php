<?php

use App\Actions\Cart\ResolveCartOwner;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

test('an active guest cart follows its server session across application key rotation', function () {
    $oldApplicationKey = 'base64:'.base64_encode(str_repeat('o', 32));
    $newApplicationKey = 'base64:'.base64_encode(str_repeat('n', 32));
    $rawToken = bin2hex(random_bytes(32));
    $oldHmac = hash_hmac('sha256', $rawToken, $oldApplicationKey);
    $newHmac = hash_hmac('sha256', $rawToken, $newApplicationKey);
    $session = new Store('cart-owner-rotation-test', new ArraySessionHandler(120));
    $session->start();
    $session->put(ResolveCartOwner::SESSION_KEY, $rawToken);
    $request = Request::create('/');
    $request->setLaravelSession($session);
    $cart = Cart::query()->create([
        'session_key' => $oldHmac,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    config()->set('app.key', $newApplicationKey);
    config()->set('app.previous_keys', [$oldApplicationKey]);

    $owner = app(ResolveCartOwner::class)->forRequest($request);

    expect($owner->sessionKey())->toBe($newHmac)
        ->and(Cart::query()->activeForOwner($owner)->sole()->is($cart))->toBeTrue()
        ->and($cart->fresh()->session_key)->toBe($newHmac)
        ->and($cart->fresh()->active_owner_key)->toBe("guest:{$newHmac}");
});
