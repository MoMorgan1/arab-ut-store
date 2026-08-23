<?php

declare(strict_types=1);

use App\Actions\AI\BuildAssistantCartOffer;
use App\Actions\AI\BuildAssistantChoices;

function cartOffer(string $text): ?array
{
    return app(BuildAssistantCartOffer::class)->execute($text);
}

test('a fully specified console order earns an add-to-cart offer', function () {
    expect(cartOffer('ابي مليون كوينز بلايستيشن سريع'))->toBe([
        'version' => 'cart.v1',
        'service' => 'coins',
        'selection' => [
            'platform' => 'playstation',
            'delivery' => 'fast',
            'quantity' => 1_000_000,
        ],
    ]);
});

test('PC carries no delivery because PC is sold at one speed', function () {
    expect(cartOffer('ابي نص مليون كوينز بي سي'))->toBe([
        'version' => 'cart.v1',
        'service' => 'coins',
        'selection' => [
            'platform' => 'pc',
            'quantity' => 500_000,
        ],
    ]);
});

test('a console order without a chosen speed is not cart-ready', function () {
    // Normal and fast are different products at different prices. Guessing
    // would put the wrong item in someone's cart.
    expect(cartOffer('ابي مليون كوينز بلايستيشن'))->toBeNull();
});

test('a quantity with no platform is not cart-ready', function () {
    expect(cartOffer('ابي مليون كوينز'))->toBeNull();
});

test('Xbox resolves to a quantity only, which is not enough to sell', function () {
    // The coins configurator sells console coins under one PlayStation option,
    // so an Xbox message never names a platform the cart endpoint accepts.
    expect(cartOffer('ابي مليون كوينز اكس بوكس'))->toBeNull();
});

test('an order-status question never earns a buy button', function () {
    expect(cartOffer('وين طلبي رقم 5000 مليون كوينز بلايستيشن سريع'))->toBeNull();
});

test('a support question about a service earns no offer', function () {
    expect(cartOffer('كم مدة الضمان؟'))->toBeNull();
});

test('empty text earns no offer', function () {
    expect(cartOffer('   '))->toBeNull();
});

test('an offer and a question are never both on one reply', function () {
    // The two answer the same thing: chips while something is unchosen, the
    // offer once nothing is. Every message that earns an offer must have
    // nothing left to ask.
    foreach (['ابي مليون كوينز بلايستيشن سريع', 'ابي نص مليون كوينز بي سي'] as $text) {
        expect(cartOffer($text))->not->toBeNull()
            ->and(app(BuildAssistantChoices::class)->execute($text, 'ar'))->toBeNull();
    }
});

test('an English console order earns the same offer', function () {
    expect(cartOffer('I want 1m coins on playstation, fast delivery'))->toBe([
        'version' => 'cart.v1',
        'service' => 'coins',
        'selection' => [
            'platform' => 'playstation',
            'delivery' => 'fast',
            'quantity' => 1_000_000,
        ],
    ]);
});

test('the offer never carries a price', function () {
    // Chat history is permanent and prices move. A price frozen into a message
    // becomes a lie the store has to honour.
    $offer = cartOffer('ابي مليون كوينز بلايستيشن سريع');

    expect(array_keys($offer ?? []))->toBe(['version', 'service', 'selection'])
        ->and(array_keys($offer['selection'] ?? []))
        ->toBe(['platform', 'delivery', 'quantity']);
});
