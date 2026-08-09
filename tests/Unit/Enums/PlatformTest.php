<?php

use App\Enums\Market;
use App\Enums\Platform;

test('customer platforms preserve their identity while mapping to supplier markets', function (string $platform, string $market) {
    expect(Platform::from($platform)->market())->toBe(Market::from($market));
})->with([
    'PlayStation uses the console market' => ['playstation', 'console'],
    'Xbox uses the console market' => ['xbox', 'console'],
    'PC uses the PC market' => ['pc', 'pc'],
]);

test('platforms expose stable integration values', function () {
    expect(Platform::PlayStation->value)->toBe('playstation')
        ->and(Platform::Xbox->value)->toBe('xbox')
        ->and(Platform::Pc->value)->toBe('pc');
});
