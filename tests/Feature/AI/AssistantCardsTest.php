<?php

declare(strict_types=1);

use App\Actions\AI\BuildAssistantCards;

function assistantCards(string $text, string $locale = 'ar'): array
{
    return app(BuildAssistantCards::class)->execute($text, $locale);
}

test('a coins question offers the coins card', function () {
    $cards = assistantCards('ابغى مليون كوينز بلايستيشن');

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['id'])->toBe('coins')
        ->and($cards[0]['url'])->toBe('/#coins')
        ->and($cards[0]['title'])->toBe('شحن كوينز FC')
        ->and($cards[0]['cta'])->toBe('اطلب الآن');
});

test('an English conversation links into the English storefront', function () {
    $cards = assistantCards('I want to buy coins', 'en');

    expect($cards[0]['url'])->toBe('/en#coins')
        ->and($cards[0]['title'])->toBe('FC Coins');
});

test('each service question offers its own card', function () {
    expect(assistantCards('كم سعر تحديات SBC')[0]['url'])->toBe('/sbc')
        ->and(assistantCards('ابغى تصعيد رايفلز')[0]['url'])->toBe('/rivals')
        ->and(assistantCards('ابغى فوت شامبيونز رانك 1')[0]['url'])->toBe('/fut-champions');
});

test('a policy question offers no card', function () {
    // A warranty answer is support, not an invitation to buy.
    expect(assistantCards('كم مدة الضمان بعد الشحن؟'))->toBe([]);
});

test('a greeting offers no card', function () {
    expect(assistantCards('السلام عليكم'))->toBe([]);
});

test('the coins card is never duplicated by two coins topics', function () {
    $cards = assistantCards('ابغى كوينز والشحن السريع كم ياخذ وقت');

    expect(array_column($cards, 'id'))->toBe(['coins']);
});

test('cards never carry a price', function () {
    foreach (assistantCards('ابغى مليون كوينز') as $card) {
        expect($card['subtitle'])->not->toMatch('/\d+\s*(ريال|SAR|\$)/')
            ->and($card)->not->toHaveKey('price');
    }
});
