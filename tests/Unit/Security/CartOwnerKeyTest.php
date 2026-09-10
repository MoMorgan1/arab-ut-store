<?php

use App\Security\CartOwnerKey;

test('a user owner key canonicalises to its integer id', function (): void {
    expect(CartOwnerKey::canonical('user:42'))->toBe(['user_id' => 42]);
});

test('a guest owner key is kept verbatim', function (): void {
    $key = 'guest:'.str_repeat('a', 64);

    expect(CartOwnerKey::canonical($key))->toBe(['owner_key' => $key]);
});

test('anything else is rejected', function (string $bad): void {
    expect(fn () => CartOwnerKey::canonical($bad))->toThrow(InvalidArgumentException::class);
})->with(['user:0', 'user:abc', 'guest:short', 'admin:1', '']);
