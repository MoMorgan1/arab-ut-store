<?php

use App\Actions\Cart\AcquireActiveCart;
use App\Models\Cart;
use App\Models\User;
use App\ValueObjects\Cart\CartOwner;

test('the production acquisition boundary returns one active cart for each owner', function () {
    $acquire = app(AcquireActiveCart::class);
    $userOwner = CartOwner::user(User::factory()->create()->id);
    $guestOwner = CartOwner::guest(hash('sha256', 'acquisition-guest-owner'));

    $firstUserCart = $acquire->execute($userOwner);
    $secondUserCart = $acquire->execute($userOwner);
    $firstGuestCart = $acquire->execute($guestOwner);
    $secondGuestCart = $acquire->execute($guestOwner);

    expect($secondUserCart->is($firstUserCart))->toBeTrue()
        ->and($secondGuestCart->is($firstGuestCart))->toBeTrue()
        ->and(Cart::query()->activeForOwner($userOwner)->count())->toBe(1)
        ->and(Cart::query()->activeForOwner($guestOwner)->count())->toBe(1);
});

test('the production acquisition boundary reuses a guest identity after a historical cart', function () {
    $owner = CartOwner::guest(hash('sha256', 'historical-acquisition-owner'));
    Cart::query()->create([
        'session_key' => $owner->sessionKey(),
        'status' => 'converted',
        'currency' => 'SAR',
    ]);

    $activeCart = app(AcquireActiveCart::class)->execute($owner);

    expect($activeCart->status)->toBe('active')
        ->and(Cart::query()->where('session_key', $owner->sessionKey())->count())->toBe(2)
        ->and(Cart::query()->activeForOwner($owner)->sole()->is($activeCart))->toBeTrue();
});
