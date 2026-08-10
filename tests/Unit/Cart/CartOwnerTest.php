<?php

use App\Actions\Cart\ResolveCartOwner;
use App\Models\User;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

uses(TestCase::class);

test('a guest owner is stable within one server session and opaque outside it', function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    $request = cartOwnerRequest();
    $resolver = app(ResolveCartOwner::class);

    $first = $resolver->forRequest($request);
    $second = $resolver->forRequest($request);
    $rawSessionToken = $request->session()->get(ResolveCartOwner::SESSION_KEY);

    expect($rawSessionToken)->toBeString()
        ->and(strlen($rawSessionToken))->toBe(64)
        ->and($first->databaseKey())->toMatch('/\Aguest:[0-9a-f]{64}\z/D')
        ->and($first->databaseKey())->toBe($second->databaseKey())
        ->and($first->sessionKey())->toBe(substr($first->databaseKey(), 6))
        ->and($first->userId())->toBeNull()
        ->and($first->idempotencyScope())->toBe($first->databaseKey())
        ->and($first->databaseKey())->not->toContain($rawSessionToken)
        ->and(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain($rawSessionToken);
});

test('different server sessions resolve to different guest owners', function () {
    $resolver = app(ResolveCartOwner::class);

    expect($resolver->forRequest(cartOwnerRequest())->databaseKey())
        ->not->toBe($resolver->forRequest(cartOwnerRequest())->databaseKey());
});

test('an authenticated owner takes precedence without creating a guest token', function () {
    $request = cartOwnerRequest();
    $user = new User;
    $user->forceFill(['id' => 37]);
    $request->setUserResolver(fn (): User => $user);

    $owner = app(ResolveCartOwner::class)->forRequest($request);

    expect($owner->databaseKey())->toBe('user:37')
        ->and($owner->userId())->toBe(37)
        ->and($owner->sessionKey())->toBeNull()
        ->and($owner->idempotencyScope())->toBe('user:37')
        ->and($request->session()->has(ResolveCartOwner::SESSION_KEY))->toBeFalse();
});

test('cart owners reject invalid database identities', function () {
    expect(fn () => CartOwner::user(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CartOwner::guest(str_repeat('A', 64)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CartOwner::guest(str_repeat('a', 63)))->toThrow(InvalidArgumentException::class);
});

function cartOwnerRequest(): Request
{
    $session = new Store('cart-owner-test', new ArraySessionHandler(120));
    $session->start();
    $request = Request::create('/');
    $request->setLaravelSession($session);

    return $request;
}
